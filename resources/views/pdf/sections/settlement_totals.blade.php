{{-- Totales de la liquidación de compra (tipo 04): muestra la retención y el neto a pagar al vendedor --}}
@php
    $m = ($tipo_moneda ?? 'PEN') !== 'PEN' ? $tipo_moneda : ($moneda_simbolo ?? 'S/');
    $rentaPct = ($porcentaje_renta == floor($porcentaje_renta)) ? (int) $porcentaje_renta : rtrim(rtrim(number_format($porcentaje_renta, 2, '.', ''), '0'), '.');
    $igvPct = (!empty($igv_pct)) ? (($igv_pct == floor($igv_pct)) ? (int) $igv_pct : rtrim(rtrim(number_format($igv_pct, 2, '.', ''), '0'), '.')) : 0;
@endphp
<table class="totals-table">
    @if(($mto_oper_gravadas ?? 0) > 0)
    <tr><td class="total-label">Op. Gravadas:</td><td class="total-value">{{ $m }} {{ number_format($mto_oper_gravadas, 2) }}</td></tr>
    @endif
    @if(($mto_oper_exoneradas ?? 0) > 0)
    <tr><td class="total-label">Op. Exoneradas:</td><td class="total-value">{{ $m }} {{ number_format($mto_oper_exoneradas, 2) }}</td></tr>
    @endif
    @if(($mto_oper_inafectas ?? 0) > 0)
    <tr><td class="total-label">Op. Inafectas:</td><td class="total-value">{{ $m }} {{ number_format($mto_oper_inafectas, 2) }}</td></tr>
    @endif
    <tr><td class="total-label">IGV ({{ $igvPct }}%):</td><td class="total-value">{{ $m }} {{ number_format($mto_igv ?? 0, 2) }}</td></tr>
    <tr><td class="total-label">Valor de venta:</td><td class="total-value">{{ $m }} {{ number_format($valor_venta ?? 0, 2) }}</td></tr>
    <tr><td class="total-label">Importe total:</td><td class="total-value">{{ $m }} {{ number_format($sub_total ?? 0, 2) }}</td></tr>
    <tr><td class="total-label">Retención Renta ({{ $rentaPct }}%):</td><td class="total-value">-{{ $m }} {{ number_format($retencion_renta ?? 0, 2) }}</td></tr>
    <tr class="total-final"><td class="total-label">NETO A PAGAR:</td><td class="total-value">{{ $m }} {{ number_format($neto ?? 0, 2) }}</td></tr>
</table>
<div style="clear: both;"></div>
