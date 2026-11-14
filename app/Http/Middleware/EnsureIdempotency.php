<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotencia a nivel API. Si el cliente envía la cabecera `Idempotency-Key` en
 * una petición de escritura (POST/PUT/PATCH/DELETE), la primera respuesta se
 * guarda y se devuelve idéntica ante la misma key → un reintento tras un timeout
 * NO vuelve a emitir el comprobante (evita duplicados).
 *
 * - Misma key + cuerpo distinto → 422 (uso incorrecto de la key).
 * - Misma key mientras la primera aún corre → 409 (en proceso).
 * - Respuestas 5xx NO se guardan (se libera la key para permitir reintento).
 */
class EnsureIdempotency
{
    private const METODOS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $tenant = $request->get('tenant');

        if (! in_array($request->method(), self::METODOS, true) || ! $key || ! $tenant) {
            return $next($request);
        }

        $key = substr((string) $key, 0, 255);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existente = DB::table('idempotency_keys')
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existente) {
            if (! hash_equals((string) $existente->request_hash, $hash)) {
                return response()->json([
                    'estado' => 'error',
                    'mensaje' => 'La Idempotency-Key ya se usó con un cuerpo distinto.',
                ], 422);
            }
            if ($existente->response_status !== null) {
                return response($existente->response_body, (int) $existente->response_status)
                    ->header('Content-Type', 'application/json')
                    ->header('Idempotency-Replayed', 'true');
            }

            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Ya hay una solicitud en proceso con esa Idempotency-Key.',
            ], 409);
        }

        // Reserva la key. El índice único evita que dos peticiones concurrentes
        // la inserten a la vez: la segunda cae en el catch → 409 (en proceso).
        try {
            DB::table('idempotency_keys')->insert([
                'tenant_id' => $tenant->id,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'response_status' => null,
                'response_body' => null,
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Ya hay una solicitud en proceso con esa Idempotency-Key.',
            ], 409);
        }

        $response = $next($request);
        $status = $response->getStatusCode();
        $body = method_exists($response, 'getContent') ? $response->getContent() : false;

        if ($status < 500 && $body !== false) {
            DB::table('idempotency_keys')
                ->where('tenant_id', $tenant->id)->where('idempotency_key', $key)
                ->update(['response_status' => $status, 'response_body' => $body]);
            $response->headers->set('Idempotency-Replayed', 'false');
        } else {
            // Error de servidor o respuesta no almacenable → liberar para reintento.
            DB::table('idempotency_keys')
                ->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->delete();
        }

        return $response;
    }
}
