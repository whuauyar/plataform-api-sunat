<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ops\HealthChecks;
use App\Support\Ops\OpsAlert;
use Illuminate\Console\Command;

/**
 * Chequeo de salud de la infraestructura: base de datos, Redis (si se usa),
 * espacio en disco, almacenamiento y estado del circuit breaker de SUNAT.
 *
 * Comparte la lógica con el endpoint `/api/health` vía App\Support\Ops\HealthChecks.
 * Con --alertar avisa por OpsAlert (con throttle por componente para no spamear).
 */
class RevisarSalud extends Command
{
    protected $signature = 'ops:salud {--alertar : enviar alerta de ops por cada problema}';

    protected $description = 'Revisa BD, Redis, disco, almacenamiento y el circuit breaker de SUNAT.';

    public function handle(HealthChecks $health): int
    {
        $checks = $health->ejecutar();
        $filas = [];

        foreach ($checks as $c) {
            $filas[] = [$c['nombre'], strtoupper($c['estado']), $c['detalle']];

            if ($c['estado'] !== 'ok' && $this->option('alertar')) {
                $nivel = $c['estado'] === 'falla' ? OpsAlert::CRITICO : OpsAlert::AVISO;
                OpsAlert::enviar(
                    $nivel,
                    "Salud: {$c['nombre']}",
                    $c['detalle'] !== '' ? $c['detalle'] : 'con problemas',
                    [],
                    dedupeKey: $c['clave'],
                );
            }
        }

        $this->table(['Componente', 'Estado', 'Detalle'], $filas);

        $resumen = HealthChecks::resumen($checks);

        if ($resumen['overall'] === 'ok') {
            $this->info('Todo saludable.');

            return self::SUCCESS;
        }

        $this->warn("Estado general: {$resumen['overall']}.");

        return self::FAILURE;
    }
}
