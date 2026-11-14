<?php

namespace App\Services\Documents;

/**
 * Montos de la liquidación de compra según las reglas de validación SUNAT (hoja LiquidacionCompra2_0):
 *
 *   Por ítem:   valor de venta = cantidad × valor unitario                         (4288)
 *               IGV   = valor de venta × tasa IGV           (solo gravado, 10)      (3103)
 *               renta = valor de venta × tasa de retención                          (2464)
 *               precio unitario = (valor de venta + IGV) / cantidad                 (4287)
 *   Globales:   IGV = Σ bases gravadas × tasa IGV                                   (4290)
 *               retención = Σ retención de los ítems                                (4346)
 *               subtotal (TaxInclusive) = valor de venta + IGV                      (4310)
 *               neto (Payable) = valor de venta − retención                         (4312)
 *
 * Tasa de retención (RS 234-2005/SUNAT y config/liquidacion.php): oro (0503) 4 %; productos primarios
 * agropecuarios excluidos hasta agro_exento_hasta; sin retención si el importe de la operación sin
 * tributos es ≤ S/ 700; en otro caso 1.5 %.
 */
class LiquidacionCompraCalculator
{
    /** @return array{porcentaje: float, motivo: string} */
    public function tasaRenta(string $tipoOperacion, bool $agropecuarios, string $fechaEmision, float $importeOperacion): array
    {
        $agroHasta = (string) config('liquidacion.agro_exento_hasta');
        if ($agropecuarios && $agroHasta !== '' && $fechaEmision <= $agroHasta) {
            return ['porcentaje' => 0.0, 'motivo' => 'productos primarios agropecuarios excluidos de la retención hasta '.$agroHasta];
        }

        $minimo = (float) config('liquidacion.retencion_minimo');
        if ($importeOperacion <= $minimo) {
            return ['porcentaje' => 0.0, 'motivo' => 'importe de la operación no supera S/ '.rtrim(rtrim(number_format($minimo, 2, '.', ''), '0'), '.')];
        }

        if ($tipoOperacion === '0503') {
            return ['porcentaje' => (float) config('liquidacion.retencion_tasa_oro'), 'motivo' => 'compra de oro'];
        }

        return ['porcentaje' => (float) config('liquidacion.retencion_tasa'), 'motivo' => 'tasa general'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $input  ítems de la petición
     * @return array{items: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function calculate(array $input, string $tipoOperacion, bool $agropecuarios, string $fechaEmision): array
    {
        $tasaIgv = (float) config('liquidacion.igv_tasa');
        $items = [];
        $gravadas = 0.0;
        $exoneradas = 0.0;
        $inafectas = 0.0;

        foreach ($input as $it) {
            $cantidad = (float) $it['cantidad'];
            $vu = (float) $it['valor_unitario'];
            $afe = isset($it['tip_afe_igv']) && $it['tip_afe_igv'] !== null && $it['tip_afe_igv'] !== ''
                ? (string) $it['tip_afe_igv'] : '10';
            $vv = round($cantidad * $vu, 2);

            if ($afe === '20') {
                $exoneradas += $vv;
            } elseif ($afe === '30') {
                $inafectas += $vv;
            } else {
                $gravadas += $vv;
            }

            $items[] = [
                'codigo' => $it['codigo'] ?? null,
                'cod_producto_sunat' => (string) $it['cod_producto_sunat'],
                'descripcion' => (string) $it['descripcion'],
                'unidad' => isset($it['unidad']) && $it['unidad'] !== null && $it['unidad'] !== '' ? (string) $it['unidad'] : 'KGM',
                'cantidad' => $cantidad,
                'valor_unitario' => $vu,
                'tip_afe_igv' => $afe,
                'valor_venta' => $vv,
                'oro' => isset($it['oro']) && is_array($it['oro']) ? $it['oro'] : null,
            ];
        }

        $valorVenta = round($gravadas + $exoneradas + $inafectas, 2);
        $tasa = $this->tasaRenta($tipoOperacion, $agropecuarios, $fechaEmision, $valorVenta);
        $renta = 0.0;

        foreach ($items as &$m) {
            $vv = (float) $m['valor_venta'];
            $pIgv = $m['tip_afe_igv'] === '10' ? $tasaIgv : 0.0;
            $igv = round($vv * $pIgv / 100, 2);
            $r = round($vv * $tasa['porcentaje'] / 100, 2);
            $m['precio_unitario'] = $m['cantidad'] > 0 ? round(($vv + $igv) / $m['cantidad'], 10) : 0.0;
            $m['porcentaje_igv'] = $pIgv;
            $m['igv'] = $igv;
            $m['porcentaje_renta'] = $tasa['porcentaje'];
            $m['renta'] = $r;
            $renta += $r;
        }
        unset($m);

        $igv = round($gravadas * $tasaIgv / 100, 2);
        $subTotal = round($valorVenta + $igv, 2);

        $totals = [
            'mto_oper_gravadas' => round($gravadas, 2),
            'mto_oper_exoneradas' => round($exoneradas, 2),
            'mto_oper_inafectas' => round($inafectas, 2),
            'porcentaje_igv' => $tasaIgv,
            'mto_igv' => $igv,
            'valor_venta' => $valorVenta,
            'mto_base_renta' => $valorVenta,
            'porcentaje_renta' => $tasa['porcentaje'],
            'motivo_tasa_renta' => $tasa['motivo'],
            'mto_retencion_renta' => round($renta, 2),
            'sub_total' => $subTotal,
            'mto_imp_neto' => round($subTotal - $igv - $renta, 2),
        ];

        return ['items' => $items, 'totals' => $totals];
    }
}
