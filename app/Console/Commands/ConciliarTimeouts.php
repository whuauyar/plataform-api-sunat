<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Documents\ReconcileFromCdrAction;
use App\Models\Perception;
use App\Models\PurchaseSettlement;
use App\Models\Retention;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Cierra el ciclo de los comprobantes que quedaron en pendiente/SUNAT_TIMEOUT (un
 * timeout de SUNAT NO es rechazo). Para los tipos con CDR individual
 * (retención 20, percepción 40, liquidación 04) CONSULTA el CDR en SUNAT y resuelve
 * el estado real; NUNCA reenvía (evita duplicados). Idempotente.
 *
 *   php artisan sunat:conciliar-timeouts
 *   php artisan sunat:conciliar-timeouts --tenant=5
 *
 * Nota: los comprobantes por ticket (resumen/baja/reversión/guía) no se concilian por
 * CDR; su resolución es por ticket y queda fuera de este comando.
 */
class ConciliarTimeouts extends Command
{
    protected $signature = 'sunat:conciliar-timeouts {--tenant= : Limitar a un tenant}';

    protected $description = 'Consulta el CDR de los comprobantes en SUNAT_TIMEOUT (retención/percepción/liquidación) y resuelve su estado sin reenviar.';

    /** modelo => [tipo SUNAT, subcarpeta de almacenamiento del CDR] */
    private const TIPOS = [
        Retention::class => ['20', 'retentions'],
        Perception::class => ['40', 'perceptions'],
        PurchaseSettlement::class => ['04', 'liquidaciones_compra'],
    ];

    public function handle(ReconcileFromCdrAction $reconcile): int
    {
        $tenantId = $this->option('tenant');
        $totales = ['aceptado' => 0, 'rechazado' => 0, 'sin_cambio' => 0];

        foreach (self::TIPOS as $modelo => [$tipo, $carpeta]) {
            $query = $modelo::query()
                ->where('sunat_status', 'pendiente')
                ->where('sunat_code', 'SUNAT_TIMEOUT');

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $query->chunkById(100, function ($docs) use ($reconcile, $tipo, $carpeta, &$totales) {
                foreach ($docs as $doc) {
                    $tenant = Tenant::find($doc->tenant_id);
                    if (! $tenant) {
                        $totales['sin_cambio']++;

                        continue;
                    }

                    $cdrPath = "{$tenant->ruc}/{$carpeta}/R-{$doc->serie}-{$doc->correlativo}.zip";
                    $resultado = $reconcile->execute($doc, $tenant, $tipo, $cdrPath);
                    $totales[$resultado]++;
                }
            });
        }

        $this->info(sprintf(
            'Conciliación: %d aceptado(s), %d rechazado(s), %d sin cambio (siguen por confirmar).',
            $totales['aceptado'],
            $totales['rechazado'],
            $totales['sin_cambio'],
        ));

        return self::SUCCESS;
    }
}
