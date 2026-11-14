<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boleta;
use App\Models\Summary;
use Illuminate\Console\Command;

/**
 * Restaura boletas marcadas como 'rechazado' que en realidad fueron ACEPTADAS en un
 * resumen diario de envío aceptado. Es el colateral del error SUNAT 2282 "documento ya
 * informado anteriormente": al generarse resúmenes duplicados del mismo día, el primero
 * queda aceptado y los siguientes rechazados; antes, el ticket del resumen rechazado
 * degradaba las boletas a 'rechazado' aunque ya estuvieran aceptadas en el primero.
 *
 * La causa raíz ya está corregida en CheckSummaryTicketStatus (no se degradan boletas
 * ya aceptadas). Este comando repara los datos existentes. Idempotente y DB-agnóstico
 * (no usa whereJsonContains, para funcionar igual en MySQL y PostgreSQL).
 */
class RepararBoletasResumenAceptado extends Command
{
    protected $signature = 'comprobantes:reparar-boletas-resumen
        {--tenant= : limitar a una empresa (tenant_id)}
        {--dry-run : mostrar qué se repararía sin modificar}';

    protected $description = "Restaura a 'aceptado' las boletas marcadas 'rechazado' que figuran en un resumen de envío aceptado (colateral del error 2282).";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tenantOpt = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        // Ids de boletas realmente aceptadas: las que están en un resumen de ENVÍO aceptado.
        $idsAceptadas = Summary::query()
            ->where('tipo', 'envio')
            ->where('sunat_status', 'aceptado')
            ->when($tenantOpt, fn ($q) => $q->where('tenant_id', $tenantOpt))
            ->get(['document_ids'])
            ->flatMap(fn (Summary $s) => $s->document_ids ?? [])
            ->unique()
            ->values();

        if ($idsAceptadas->isEmpty()) {
            $this->info('No hay resúmenes de envío aceptados; nada que reparar.');

            return self::SUCCESS;
        }

        $boletas = Boleta::query()
            ->whereIn('id', $idsAceptadas->all())
            ->where('sunat_status', 'rechazado')
            ->when($tenantOpt, fn ($q) => $q->where('tenant_id', $tenantOpt))
            ->get();

        if ($boletas->isEmpty()) {
            $this->info('Nada que reparar: no hay boletas en "rechazado" que figuren en un resumen aceptado.');

            return self::SUCCESS;
        }

        $filas = [];
        foreach ($boletas as $b) {
            $filas[] = [$b->tenant_id, "{$b->serie}-{$b->correlativo}", (string) $b->sunat_code, 'rechazado → aceptado'];

            if (! $dry) {
                $b->update([
                    'sunat_status' => 'aceptado',
                    'sunat_code' => '0',
                    'sunat_description' => 'Aceptado mediante resumen diario (reparación 2282).',
                ]);
            }
        }

        $this->table(['Empresa', 'Boleta', 'Código previo', 'Acción'], $filas);

        $n = count($filas);
        $this->{$dry ? 'warn' : 'info'}(
            $dry
                ? "{$n} boleta(s) se repararían (dry-run; nada modificado)."
                : "{$n} boleta(s) restauradas a 'aceptado'."
        );

        return self::SUCCESS;
    }
}
