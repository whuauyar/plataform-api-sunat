<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Solo por cabecera: NO se acepta la credencial por query string, para que
        // no quede expuesta en logs de acceso, historial del navegador ni proxies.
        $apiKey = $request->header('X-Api-Key');
        $apiSecret = $request->header('X-Api-Secret');

        if (! $apiKey || ! $apiSecret) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Las cabeceras X-Api-Key y X-Api-Secret son requeridas.',
            ], 401);
        }

        $tenant = Cache::remember("tenant:key:{$apiKey}", 600, function () use ($apiKey) {
            return Tenant::where('api_key', $apiKey)->first();
        });

        if (! $tenant) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Credenciales de API inválidas.',
            ], 401);
        }

        // Leer api_secret puede lanzar DecryptException si el valor guardado no es un
        // sobre cifrado válido para la APP_KEY actual (credencial heredada/en texto plano,
        // o APP_KEY distinta). No tumbamos la petición con un 500: devolvemos 401 con una
        // pista accionable para regenerar la credencial desde el panel.
        try {
            $secretValido = hash_equals((string) $tenant->api_secret, $apiSecret);
        } catch (DecryptException $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'No se pudo validar el API Secret de la empresa (credencial ilegible). Regenérela desde el panel: Configuración → Credenciales → Regenerar.',
            ], 401);
        }

        if (! $secretValido) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Credenciales de API inválidas.',
            ], 401);
        }

        if (! $tenant->is_active) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => 'Empresa desactivada. Contacte al administrador.',
            ], 403);
        }

        $request->merge(['tenant' => $tenant]);

        return $next($request);
    }
}
