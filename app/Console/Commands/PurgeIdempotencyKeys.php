<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene acotada la tabla idempotency_keys (crece una fila por cada POST/PUT con
 * Idempotency-Key y nunca se limpiaba). Hace dos cosas:
 *
 *   1. Borra llaves YA RESUELTAS más viejas que --days: ningún cliente reintenta con
 *      la misma key días después, así que su único efecto sería ocupar espacio.
 *   2. Libera RESERVAS EN PROCESO colgadas (response_status NULL) más viejas que
 *      --stale-minutes: si una petición murió sin registrar respuesta, la key quedaba
 *      reservada y el cliente recibía 409 "en proceso" para siempre. Liberarla permite
 *      reintentar.
 *
 *   php artisan idempotency:purge
 *   php artisan idempotency:purge --days=3 --stale-minutes=30
 */
class PurgeIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:purge
                            {--days=7 : Elimina llaves ya resueltas con más de N días}
                            {--stale-minutes=60 : Libera reservas en proceso (sin respuesta) más viejas que N minutos}';

    protected $description = 'Purga llaves de idempotencia viejas y libera reservas en proceso colgadas.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $staleMin = max(0, (int) $this->option('stale-minutes'));

        $viejas = DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $colgadas = 0;
        if ($staleMin > 0) {
            $colgadas = DB::table('idempotency_keys')
                ->whereNull('response_status')
                ->where('created_at', '<', now()->subMinutes($staleMin))
                ->delete();
        }

        $this->info("Idempotencia purgada: {$viejas} llave(s) resuelta(s) > {$days}d y {$colgadas} reserva(s) en proceso colgada(s) > {$staleMin}m.");

        return self::SUCCESS;
    }
}
