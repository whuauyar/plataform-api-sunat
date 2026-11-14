<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Services\Sunat\SunatCircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Chequeos de salud de la infraestructura, compartidos por el comando
 * `ops:salud` (que alerta) y el endpoint `/api/health` (que responde a monitores).
 *
 * Cada chequeo devuelve:
 *   - nombre:  etiqueta legible
 *   - clave:   identificador estable (para throttle de alertas)
 *   - estado:  'ok' | 'aviso' | 'falla'
 *   - detalle: texto corto, sin datos sensibles
 *   - afecta_disponibilidad: si su 'falla' significa que NUESTRA API está caída
 *     (BD/Redis/almacenamiento). SUNAT o el disco NO tumban la API → no da 503.
 */
final class HealthChecks
{
    public function __construct(private SunatCircuitBreaker $breaker) {}

    /**
     * @return list<array{nombre:string,clave:string,estado:string,detalle:string,afecta_disponibilidad:bool}>
     */
    public function ejecutar(): array
    {
        return array_values(array_merge(
            [$this->bd()],
            $this->usaRedis() ? [$this->redis()] : [],
            [$this->disco(), $this->almacenamiento()],
            $this->sunat(),
        ));
    }

    /**
     * Resumen global a partir de los chequeos.
     *
     * @param  list<array{estado:string,afecta_disponibilidad:bool}>  $checks
     * @return array{overall:string,http:int}
     */
    public static function resumen(array $checks): array
    {
        $caido = false;
        $degradado = false;

        foreach ($checks as $c) {
            if ($c['estado'] === 'falla' && $c['afecta_disponibilidad']) {
                $caido = true;
            } elseif ($c['estado'] !== 'ok') {
                $degradado = true;
            }
        }

        if ($caido) {
            return ['overall' => 'caido', 'http' => 503];
        }

        return ['overall' => $degradado ? 'degradado' : 'ok', 'http' => 200];
    }

    private function bd(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return $this->check('Base de datos', 'salud-bd', 'ok', (string) config('database.default'), true);
        } catch (Throwable $e) {
            return $this->check('Base de datos', 'salud-bd', 'falla', 'inaccesible', true);
        }
    }

    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return $this->check('Redis', 'salud-redis', 'ok', '', true);
        } catch (Throwable $e) {
            return $this->check('Redis', 'salud-redis', 'falla', 'inaccesible', true);
        }
    }

    private function disco(): array
    {
        $ruta = storage_path();
        $libre = @disk_free_space($ruta);
        $total = @disk_total_space($ruta);

        if ($libre === false || $total === false || $total <= 0) {
            return $this->check('Disco', 'salud-disco', 'aviso', 'no se pudo leer', false);
        }

        $pctLibre = (int) round(($libre / $total) * 100);
        $minimo = (int) config('ops.umbrales.disco_min_libre_pct', 10);

        // Disco bajo = degradado (aviso), no caída: la API sigue respondiendo.
        return $this->check('Disco', 'salud-disco', $pctLibre < $minimo ? 'aviso' : 'ok', "{$pctLibre}% libre", false);
    }

    private function almacenamiento(): array
    {
        try {
            $disk = Storage::disk((string) config('comprobantes.disk', 'comprobantes'));
            $probe = 'ops-health/'.uniqid('probe_', true).'.txt';
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return $this->check('Almacenamiento', 'salud-storage', 'ok', '', true);
        } catch (Throwable $e) {
            return $this->check('Almacenamiento', 'salud-storage', 'falla', 'no escribible', true);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sunat(): array
    {
        $endpoints = array_filter([
            'beta' => (string) config('facturacion.sunat.beta.fe'),
            'produccion' => (string) config('facturacion.sunat.production.fe'),
        ]);

        $checks = [];
        foreach ($endpoints as $nombre => $endpoint) {
            $estado = $this->breaker->getStats($endpoint)['state'];

            // SUNAT es externo: que esté caído NO tumba nuestra API (no da 503),
            // pero sí es un aviso operativo.
            $salud = match ($estado) {
                SunatCircuitBreaker::STATE_OPEN => 'falla',
                SunatCircuitBreaker::STATE_HALF_OPEN => 'aviso',
                default => 'ok',
            };
            $detalle = match ($estado) {
                SunatCircuitBreaker::STATE_OPEN => 'circuit breaker abierto',
                SunatCircuitBreaker::STATE_HALF_OPEN => 'recuperando',
                default => '',
            };

            $checks[] = $this->check("SUNAT {$nombre}", "salud-sunat-{$nombre}", $salud, $detalle, false);
        }

        return $checks;
    }

    /**
     * @return array{nombre:string,clave:string,estado:string,detalle:string,afecta_disponibilidad:bool}
     */
    private function check(string $nombre, string $clave, string $estado, string $detalle, bool $afectaDisponibilidad): array
    {
        return [
            'nombre' => $nombre,
            'clave' => $clave,
            'estado' => $estado,
            'detalle' => $detalle,
            'afecta_disponibilidad' => $afectaDisponibilidad,
        ];
    }

    private function usaRedis(): bool
    {
        return in_array('redis', [
            config('queue.default'),
            config('cache.default'),
            config('session.driver'),
        ], true);
    }
}
