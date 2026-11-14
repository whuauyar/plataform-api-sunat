<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\NotifyWebhookJob;
use App\Jobs\SendDocumentToSunat;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Support\Ops\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Evita que los comprobantes se pasen del plazo de envío a SUNAT (3 días calendario
 * siguientes a la fecha de emisión, R.S. 000003-2023/SUNAT; configurable en
 * facturacion.plazo_envio_dias).
 *
 * Para facturas y notas (crédito/débito) que aún NO llegaron a SUNAT
 * (pendiente/enviado):
 *   - POR VENCER (dentro de plazo, a <= --dias-aviso del límite): las AUTO-ENVÍA
 *     (salvo --sin-auto-envio), así se mandan a tiempo y conservan su validez.
 *   - VENCIDAS (plazo ya pasado): NO se auto-envían — hacerlo sería un envío
 *     extemporáneo sin validez tributaria; solo se AVISA para que el emisor decida.
 *
 * Avisa por el webhook del tenant (evento documentos.plazo_envio) y por OpsAlert.
 *
 *   php artisan sunat:plazo-envio                 # auto-envía por vencer + avisa
 *   php artisan sunat:plazo-envio --sin-auto-envio # solo avisa (no envía nada)
 *   php artisan sunat:plazo-envio --dias-aviso=2   # adelanta el aviso/envío
 */
class AlertarPlazoEnvio extends Command
{
    protected $signature = 'sunat:plazo-envio
        {--dias-aviso=1 : Avisar/auto-enviar cuando falten N días o menos para el límite}
        {--sin-auto-envio : Solo avisar; no auto-enviar los que están por vencer}';

    protected $description = 'Auto-envía los comprobantes por vencer y avisa de los vencidos según el plazo de envío a SUNAT.';

    /** modelo => etiqueta */
    private const TIPOS = [
        Invoice::class => 'Factura',
        CreditNote::class => 'Nota de crédito',
        DebitNote::class => 'Nota de débito',
    ];

    public function handle(): int
    {
        $plazo = max(1, (int) config('facturacion.plazo_envio_dias', 3));
        $diasAviso = max(0, (int) $this->option('dias-aviso'));
        $autoEnviar = ! $this->option('sin-auto-envio');
        $hoy = Carbon::today();

        /** @var array<int, array{por_vencer: list<array>, vencidos: list<array>}> $porTenant */
        $porTenant = [];
        $enviados = 0;

        foreach (self::TIPOS as $modelo => $etiqueta) {
            $modelo::query()
                ->whereIn('sunat_status', ['pendiente', 'enviado'])
                ->whereNotNull('fecha_emision')
                ->chunkById(200, function ($docs) use ($modelo, $etiqueta, $plazo, $diasAviso, $autoEnviar, $hoy, &$porTenant, &$enviados) {
                    foreach ($docs as $doc) {
                        $limite = Carbon::parse($doc->fecha_emision)->startOfDay()->addDays($plazo);
                        $diasRestantes = $hoy->diffInDays($limite, false); // >0 futuro, <0 vencido

                        $fila = [
                            'tipo' => $etiqueta,
                            'numero' => $doc->serie.'-'.str_pad((string) $doc->correlativo, 8, '0', STR_PAD_LEFT),
                            'fecha_emision' => Carbon::parse($doc->fecha_emision)->toDateString(),
                            'limite_envio' => $limite->toDateString(),
                            'dias_restantes' => $diasRestantes,
                        ];

                        if ($diasRestantes < 0) {
                            $porTenant[$doc->tenant_id]['vencidos'][] = $fila;
                        } elseif ($diasRestantes <= $diasAviso) {
                            $porTenant[$doc->tenant_id]['por_vencer'][] = $fila;
                            if ($autoEnviar) {
                                SendDocumentToSunat::dispatch($modelo, (int) $doc->id);
                                $enviados++;
                            }
                        }
                    }
                });
        }

        $totalPorVencer = 0;
        $totalVencidos = 0;

        foreach ($porTenant as $tenantId => $grupos) {
            $porVencer = $grupos['por_vencer'] ?? [];
            $vencidos = $grupos['vencidos'] ?? [];
            $totalPorVencer += count($porVencer);
            $totalVencidos += count($vencidos);

            // Aviso al tenant por su webhook (si lo tiene configurado).
            NotifyWebhookJob::dispatch(
                tenantId: $tenantId,
                event: 'documentos.plazo_envio',
                payload: [
                    'plazo_dias' => $plazo,
                    'por_vencer' => $porVencer,
                    'vencidos' => $vencidos,
                    'auto_enviados' => $autoEnviar ? count($porVencer) : 0,
                ],
            );
        }

        if ($totalPorVencer > 0 || $totalVencidos > 0) {
            OpsAlert::aviso(
                'Comprobantes cerca del plazo de envío a SUNAT',
                "Por vencer: {$totalPorVencer} (auto-enviados: ".($autoEnviar ? $totalPorVencer : 0).") · Vencidos sin aceptar: {$totalVencidos}.",
                ['tenants' => count($porTenant), 'plazo_dias' => $plazo],
                dedupeKey: 'plazo-envio:'.$hoy->toDateString(),
            );
        }

        $this->info(sprintf(
            'Plazo de envío: %d por vencer (%d auto-enviados), %d vencidas sin aceptar, en %d empresa(s).',
            $totalPorVencer,
            $autoEnviar ? $enviados : 0,
            $totalVencidos,
            count($porTenant),
        ));

        return self::SUCCESS;
    }
}
