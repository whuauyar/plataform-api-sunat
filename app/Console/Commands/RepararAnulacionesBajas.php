<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Perception;
use App\Models\Retention;
use App\Models\VoidedDocument;
use Illuminate\Console\Command;

/**
 * Repara facturas/documentos que quedaron SIN anular aunque su comunicación de
 * baja (RA/RR) fue ACEPTADA por SUNAT. Pasó por dos bugs (código 0098 tratado
 * como rechazo + la anulación solo aplicaba si el documento seguía
 * 'anulacion_en_proceso'). Idempotente: solo toca los que aún no están 'anulado'.
 */
class RepararAnulacionesBajas extends Command
{
    protected $signature = 'bajas:reparar-anulaciones
        {--tenant= : limitar a una empresa (tenant_id)}
        {--id= : reparar solo esta baja (voided id)}
        {--dry-run : mostrar qué se repararía sin modificar}';

    protected $description = 'Anula los documentos cuya comunicación de baja fue aceptada pero quedaron en otro estado.';

    private const MODEL_MAP = [
        '01' => Invoice::class,
        '03' => Boleta::class,
        '07' => CreditNote::class,
        '08' => DebitNote::class,
        '20' => Retention::class,
        '40' => Perception::class,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $bajas = VoidedDocument::query()
            ->where('sunat_status', 'aceptado')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('id'), fn ($q) => $q->whereKey((int) $this->option('id')))
            ->get();

        $filas = [];
        $reparados = 0;

        foreach ($bajas as $baja) {
            foreach ($baja->detalles ?? [] as $detalle) {
                $model = self::MODEL_MAP[$detalle['tipo_documento'] ?? null] ?? null;
                $serie = $detalle['serie'] ?? null;
                $correlativo = $detalle['correlativo'] ?? null;
                if (! $model || ! $serie || ! $correlativo) {
                    continue;
                }

                $doc = $model::where('tenant_id', $baja->tenant_id)
                    ->where('serie', $serie)
                    ->where('correlativo', $correlativo)
                    ->first();

                if (! $doc || $doc->sunat_status === 'anulado') {
                    continue;
                }

                $filas[] = [$baja->identifier, "{$serie}-{$correlativo}", $doc->sunat_status, '→ anulado'];
                $reparados++;

                if (! $dry) {
                    $doc->update(['sunat_status' => 'anulado']);
                }
            }
        }

        if ($filas === []) {
            $this->info('Nada que reparar: todas las bajas aceptadas ya tienen sus documentos anulados.');

            return self::SUCCESS;
        }

        $this->table(['Baja', 'Documento', 'Estado actual', 'Acción'], $filas);
        $this->{$dry ? 'warn' : 'info'}(
            $dry
                ? "{$reparados} documento(s) se repararían (dry-run; nada modificado)."
                : "{$reparados} documento(s) anulado(s)."
        );

        return self::SUCCESS;
    }
}
