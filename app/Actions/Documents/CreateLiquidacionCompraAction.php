<?php

namespace App\Actions\Documents;

use App\Actions\Payments\RegisterPaymentAction;
use App\Jobs\SendLiquidacionCompraToSunat;
use App\Models\PurchaseSettlement;
use App\Models\Serie;
use App\Models\Tenant;
use App\Services\DocumentCalculationService;
use App\Services\Documents\LiquidacionCompraCalculator;
use App\Services\Plan\PlanService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Crea una liquidación de compra (tipo 04): bloquea la serie, calcula montos (IGV + retención de
 * renta), persiste el comprobante y sus ítems, y encola el envío a SUNAT. Mismo patrón que
 * {@see CreateInvoiceAction}, pero sin cliente (el vendedor va embebido) y con su propia calculadora.
 */
class CreateLiquidacionCompraAction
{
    use Concerns\ResolvesEmisionDateTime;

    public function __construct(
        private LiquidacionCompraCalculator $calculator,
        private DocumentCalculationService $documentCalculator,
        private RegisterPaymentAction $paymentAction,
    ) {}

    public function execute(Tenant $tenant, array $data, bool $enviarAutomatico = true): PurchaseSettlement
    {
        return DB::transaction(function () use ($tenant, $data, $enviarAutomatico) {
            $serie = Serie::where('tenant_id', $tenant->id)
                ->where('tipo_documento', '04')
                ->where('serie', $data['serie'])
                ->where('is_active', true)
                ->with('sucursal')
                ->lockForUpdate()
                ->firstOrFail();

            $correlativo = $serie->resolveCorrelativo(isset($data['correlativo']) ? (int) $data['correlativo'] : null);
            $sucursal = $serie->sucursal;

            $fecha = $this->resolveEmisionDateTime($data['fecha_emision']);
            $tipoOp = $data['tipo_operacion'] ?? '0501';
            $agro = ! empty($data['productos_agropecuarios']);

            $fechaDate = \Illuminate\Support\Carbon::parse($fecha)->format('Y-m-d');
            $calc = $this->calculator->calculate($data['items'], $tipoOp, $agro, $fechaDate);
            $totals = $calc['totals'];

            $vend = $data['vendedor'];
            $lugar = $data['lugar'];

            $guias = ! empty($data['guias'])
                ? array_map(fn ($g) => ['tipo' => (string) $g['tipo'], 'numero' => (string) $g['numero']], $data['guias'])
                : null;

            $settlement = PurchaseSettlement::create([
                'tenant_id' => $tenant->id,
                'sucursal_id' => $sucursal?->id,
                'cod_local' => $sucursal?->cod_local ?? '0000',
                'serie' => $data['serie'],
                'correlativo' => $correlativo,
                'fecha_emision' => $fecha,
                'tipo_operacion' => $tipoOp,
                'tipo_moneda' => 'PEN',
                'vendedor_tipo_doc' => (string) $vend['tipo_doc'],
                'vendedor_num_doc' => strtoupper((string) $vend['num_doc']),
                'vendedor_nombre' => trim((string) $vend['nombre']),
                'vendedor_direccion' => trim((string) $vend['direccion']),
                'vendedor_ubigeo' => (string) $vend['ubigeo'],
                'vendedor_tipo_domicilio' => (string) $vend['tipo_domicilio'],
                'lugar_direccion' => trim((string) $lugar['direccion']),
                'lugar_ubigeo' => (string) $lugar['ubigeo'],
                'lugar_tipo' => (string) $lugar['tipo'],
                'productos_agropecuarios' => $agro,
                'mto_oper_gravadas' => $totals['mto_oper_gravadas'],
                'mto_oper_exoneradas' => $totals['mto_oper_exoneradas'],
                'mto_oper_inafectas' => $totals['mto_oper_inafectas'],
                'mto_igv' => $totals['mto_igv'],
                'porcentaje_igv' => $totals['porcentaje_igv'],
                'valor_venta' => $totals['valor_venta'],
                'mto_base_renta' => $totals['mto_base_renta'],
                'porcentaje_renta' => $totals['porcentaje_renta'],
                'mto_retencion_renta' => $totals['mto_retencion_renta'],
                'sub_total' => $totals['sub_total'],
                'mto_imp_neto' => $totals['mto_imp_neto'],
                'leyenda' => $this->documentCalculator->generateLeyenda((float) $totals['mto_imp_neto'], 'PEN'),
                'observacion' => $data['observacion'] ?? null,
                'guias' => $guias,
                'placa_vehiculo' => $data['placa_vehiculo'] ?? null,
                'sunat_status' => 'pendiente',
            ]);

            foreach ($calc['items'] as $item) {
                $settlement->items()->create($item);
            }

            Cache::forget("tenant:{$tenant->id}:doc_count:".now()->format('Y-m'));
            Cache::forget("tenant:{$tenant->id}:sunat_count:".now()->format('Y-m'));
            app(PlanService::class)->incrementUsage($tenant, 'documents');

            if ($enviarAutomatico) {
                SendLiquidacionCompraToSunat::dispatch($settlement->id);
                $settlement->update(['sunat_status' => 'enviado']);
            }

            if (! empty($data['pagos'])) {
                $this->paymentAction->execute($settlement, $data['pagos']);
            }

            return $settlement->fresh(['items', 'payments']);
        });
    }
}
