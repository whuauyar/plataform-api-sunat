<?php

declare(strict_types=1);

use App\Services\DocumentCalculationService;
use Illuminate\Validation\ValidationException;

/**
 * R3 — Verificación opcional de totales. Por defecto (off) la API respeta los
 * totales que envía el cliente sin recalcular (comportamiento histórico). Al
 * activar facturacion.verificar_totales, un total que no cuadra con el recalculado
 * se rechaza (422), como red de seguridad.
 */
function calcular(array $extraData = [], ?string $fecha = '2026-11-09'): array
{
    // 1 ítem gravado: cantidad 2 × 118 (incl. IGV 18%) → gravadas 200, IGV 36, total 236.
    $items = [['descripcion' => 'X', 'unidad' => 'NIU', 'cantidad' => 2, 'precio_unitario' => 118, 'tip_afe_igv' => '10']];
    $calc = app(DocumentCalculationService::class);
    $ci = $calc->calculateItems($items, null, $fecha);
    $data = array_merge(['items' => $items], $extraData);

    return $calc->calculateTotals($ci, $data, null, $fecha);
}

test('por defecto (verificar_totales=false) NO verifica: respeta el total del cliente', function () {
    config(['facturacion.verificar_totales' => false]);

    // El cliente envía un IGV absurdo (999); sin verificación, se usa tal cual.
    $totals = calcular(['mto_igv' => 999]);

    expect($totals['mto_igv'])->toBe(999.0);
});

test('con verificar_totales=true, un total que no cuadra se rechaza (ValidationException)', function () {
    config(['facturacion.verificar_totales' => true, 'facturacion.tolerancia_totales' => 0.10]);

    expect(fn () => calcular(['mto_igv' => 999]))
        ->toThrow(ValidationException::class);
});

test('con verificar_totales=true, totales correctos pasan sin error', function () {
    config(['facturacion.verificar_totales' => true, 'facturacion.tolerancia_totales' => 0.10]);

    // IGV 36 y total 236 son exactamente los recalculados.
    $totals = calcular(['mto_igv' => 36, 'mto_imp_venta' => 236]);

    expect($totals['mto_igv'])->toBe(36.0);
    expect($totals['mto_imp_venta'])->toBe(236.0);
});

test('con verificar_totales=true, una diferencia dentro de la tolerancia pasa', function () {
    config(['facturacion.verificar_totales' => true, 'facturacion.tolerancia_totales' => 0.10]);

    // 36.05 difiere 0.05 del recalculado 36.00 → dentro de 0.10, se acepta.
    $totals = calcular(['mto_igv' => 36.05]);

    expect($totals['mto_igv'])->toBe(36.05);
});
