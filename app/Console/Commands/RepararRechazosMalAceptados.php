<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use Illuminate\Console\Command;

/**
 * Corrige comprobantes guardados como 'aceptado' que en realidad traen un código
 * de RECHAZO de SUNAT (1000–3999), p. ej. 2108 "Presentación fuera de fecha".
 * Era un bug de interpretación del CDR (se corrigió en GreenterService::send).
 * Idempotente: solo toca los 'aceptado' con código de rechazo.
 */
class RepararRechazosMalAceptados extends Command
{
    protected $signature = 'comprobantes:reparar-rechazos
        {--tenant= : limitar a una empresa (tenant_id)}
        {--dry-run : mostrar qué se repararía sin modificar}';

    protected $description = 'Marca como rechazados los comprobantes aceptados por error que traen código de rechazo (1000-3999).';

    /** @var array<string, class-string> */
    private const MODELOS = [
        'Factura' => Invoice::class,
        'Boleta' => Boleta::class,
        'Nota crédito' => CreditNote::class,
        'Nota débito' => DebitNote::class,
        'Guía' => DispatchGuide::class,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $filas = [];
        $total = 0;

        foreach (self::MODELOS as $etiqueta => $model) {
            $docs = $model::query()
                ->where('sunat_status', 'aceptado')
                ->whereNotNull('sunat_code')
                ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
                ->get();

            foreach ($docs as $doc) {
                $code = (string) $doc->sunat_code;
                if (! ctype_digit($code)) {
                    continue;
                }
                $n = (int) $code;
                if ($n < 1000 || $n >= 4000) {
                    continue; // 0 = aceptado, 4000+ = observación (aceptado). Solo 1000–3999 es rechazo.
                }

                $filas[] = [$etiqueta, $doc->tenant_id, "{$doc->serie}-{$doc->correlativo}", $code, '→ rechazado'];
                $total++;

                if (! $dry) {
                    $doc->update(['sunat_status' => 'rechazado']);
                }
            }
        }

        if ($filas === []) {
            $this->info('Nada que reparar: no hay comprobantes aceptados con código de rechazo.');

            return self::SUCCESS;
        }

        $this->table(['Tipo', 'Empresa', 'Comprobante', 'Código', 'Acción'], $filas);
        $this->{$dry ? 'warn' : 'info'}(
            $dry ? "{$total} comprobante(s) se repararían (dry-run; nada modificado)."
                 : "{$total} comprobante(s) marcados como rechazado."
        );

        return self::SUCCESS;
    }
}
