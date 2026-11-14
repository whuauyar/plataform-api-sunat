<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = (int) ((microtime(true) - $start) * 1000);

        // Solo decodificar/almacenar un resumen truncado de la respuesta para evitar sobrecarga de memoria
        $responseContent = $response->getContent();
        $responseBody = null;
        if (strlen($responseContent) <= 4096) {
            $responseBody = json_decode($responseContent, true);
        } else {
            $decoded = json_decode($responseContent, true);
            $responseBody = [
                'estado' => $decoded['estado'] ?? null,
                'mensaje' => $decoded['mensaje'] ?? null,
                '_truncated' => true,
                '_size' => strlen($responseContent),
            ];
        }

        // La respuesta también puede traer credenciales (p. ej. regenerar credenciales),
        // así que se sanea igual que la petición antes de persistirla.
        if (is_array($responseBody)) {
            $responseBody = $this->sanitize($responseBody);
        }

        $logData = [
            'tenant_id' => $request->get('tenant')?->id,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'request_body' => $this->sanitize($request->except(['tenant'])),
            'response_body' => $responseBody,
            'status_code' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'duration_ms' => $duration,
            'created_at' => now(),
        ];

        dispatch(function () use ($logData) {
            ApiLog::create($logData);
        })->afterResponse();

        return $response;
    }

    /**
     * Reemplaza UploadedFile con metadata serializable y oculta datos sensibles (tanto
     * en la petición como en la respuesta). Sin esto, multipart/form-data (logos,
     * certificados) revienta el afterResponse() y los secretos quedarían en claro.
     */
    private function sanitize(array $data): array
    {
        array_walk_recursive($data, function (&$value, $key) {
            if ($value instanceof UploadedFile) {
                $value = [
                    '_file' => true,
                    'name' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime' => $value->getClientMimeType(),
                ];
            } elseif ($this->esSensible((string) $key) && ! empty($value)) {
                $value = '***';
            }
        });

        return $data;
    }

    /**
     * Una clave es sensible si su nombre (sin distinguir mayúsculas) contiene un
     * fragmento de credencial. Cubre variantes como api_secret, client_secret,
     * sire_client_secret, access_token, clave_sol, etc., sin enumerarlas una a una.
     */
    private function esSensible(string $key): bool
    {
        $k = strtolower($key);

        foreach (['secret', 'password', 'contrasena', 'clave', 'token', 'api_key', 'apikey'] as $fragmento) {
            if (str_contains($k, $fragmento)) {
                return true;
            }
        }

        // Claves conocidas que no encajan en los fragmentos anteriores.
        return in_array($k, ['sol_pass', 'pass'], true);
    }
}
