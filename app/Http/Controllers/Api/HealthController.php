<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Ops\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Health check profundo para monitores externos (uptime-kuma, etc.).
 *
 * Público y sin datos sensibles: solo el estado up/down de cada componente.
 * Responde 200 si la API está operativa (aunque algo esté "degradado", p. ej.
 * SUNAT caído o disco bajo) y 503 solo si un componente propio (BD, Redis,
 * almacenamiento) está caído. Cacheado unos segundos para soportar polling.
 */
class HealthController extends Controller
{
    public function __invoke(HealthChecks $health): JsonResponse
    {
        $data = Cache::remember('ops:health', 15, function () use ($health) {
            $checks = $health->ejecutar();
            $resumen = HealthChecks::resumen($checks);

            return [
                'http' => $resumen['http'],
                'cuerpo' => [
                    'estado' => $resumen['overall'], // ok | degradado | caido
                    'timestamp' => now()->toIso8601String(),
                    'checks' => array_map(static fn ($c) => [
                        'componente' => $c['nombre'],
                        'estado' => $c['estado'],   // ok | aviso | falla
                        'detalle' => $c['detalle'],
                    ], $checks),
                ],
            ];
        });

        return response()->json($data['cuerpo'], $data['http']);
    }
}
