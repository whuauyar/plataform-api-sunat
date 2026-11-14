<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ops\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta los jobs en la tabla failed_jobs y alerta si pasan del umbral.
 *
 * Un job de envío a SUNAT que agota reintentos termina aquí y nadie se entera:
 * el comprobante se queda sin llegar a SUNAT. Este chequeo lo saca a la luz.
 */
class RevisarJobsFallidos extends Command
{
    protected $signature = 'ops:jobs-fallidos {--alertar : enviar alerta de ops si hay fallidos}';

    protected $description = 'Revisa la tabla failed_jobs y alerta si hay jobs fallidos acumulados.';

    public function handle(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            $this->warn('No existe la tabla failed_jobs.');

            return self::SUCCESS;
        }

        $total = DB::table('failed_jobs')->count();
        $umbral = (int) config('ops.umbrales.jobs_fallidos', 1);

        if ($total < $umbral) {
            $this->info("Jobs fallidos: {$total} (umbral: {$umbral}). Todo en orden.");

            return self::SUCCESS;
        }

        // Desglose por cola para ubicar el problema (sunat, webhooks, mail…).
        $porCola = DB::table('failed_jobs')
            ->selectRaw('queue, COUNT(*) as total')
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get();

        $filas = $porCola->map(fn ($r) => [$r->queue, $r->total])->all();
        $this->table(['Cola', 'Fallidos'], $filas);
        $this->error("Hay {$total} job(s) fallido(s).");

        if ($this->option('alertar')) {
            $desglose = $porCola->map(fn ($r) => "{$r->queue}={$r->total}")->implode(', ');
            $ultimo = DB::table('failed_jobs')->orderByDesc('id')->value('failed_at');

            OpsAlert::critico(
                'Jobs fallidos en cola',
                "Se acumulan {$total} job(s) en failed_jobs. Revisa y reintenta (php artisan queue:retry all).",
                [
                    'por_cola' => $desglose,
                    'ultimo_fallo' => (string) $ultimo,
                ],
                dedupeKey: 'jobs-fallidos',
            );
        }

        return self::FAILURE;
    }
}
