<?php

declare(strict_types=1);

use App\Models\Sucursal;
use App\Models\Tenant;
use App\Services\Reports\ReportService;
use Illuminate\Support\Facades\DB;

/**
 * Golden-master de porSucursal: fija la salida EXACTA del comparativo por
 * sucursal sobre un dataset determinista, para que el refactor de rendimiento
 * (no hidratar modelos con ->toBase) devuelva cifras idénticas.
 */
function sembrarSucDoc(string $tabla, Tenant $t, array $over): void
{
    $base = [
        'tenant_id' => $t->id,
        'cod_local' => 'S001',
        'tipo_moneda' => 'PEN',
        'client_tipo_doc' => '6',
        'client_num_doc' => '20111',
        'client_razon_social' => 'CLIENTE A',
        'sunat_status' => 'aceptado',
        'mto_oper_gravadas' => 0,
        'mto_oper_exoneradas' => 0,
        'mto_oper_inafectas' => 0,
        'mto_oper_gratuitas' => 0,
        'mto_igv' => 0,
        'mto_imp_venta' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if (in_array($tabla, ['credit_notes', 'debit_notes'], true)) {
        $base = array_merge($base, [
            'doc_afectado_tipo' => '01', 'doc_afectado_serie' => 'F001', 'doc_afectado_correlativo' => '1',
            'cod_motivo' => '01', 'des_motivo' => 'ANULACION DE LA OPERACION',
        ]);
    }

    DB::table($tabla)->insert(array_merge($base, $over));
}

test('porSucursal devuelve el comparativo esperado sobre un dataset conocido', function () {
    $t = Tenant::factory()->create();
    $s1 = Sucursal::create(['tenant_id' => $t->id, 'cod_local' => 'S001', 'nombre' => 'Principal',
        'direccion' => 'AV. A 1', 'ubigeo' => '150101', 'is_active' => true]);
    $s2 = Sucursal::create(['tenant_id' => $t->id, 'cod_local' => 'S002', 'nombre' => 'Norte',
        'direccion' => 'AV. B 2', 'ubigeo' => '150102', 'is_active' => true]);

    // Sucursal S001: 1 factura, 1 boleta, 1 NC, 1 ND
    sembrarSucDoc('invoices', $t, ['cod_local' => 'S001', 'serie' => 'F001', 'correlativo' => 1, 'fecha_emision' => '2026-01-10',
        'mto_imp_venta' => 118.00, 'mto_igv' => 18.00, 'monto_pagado' => 118.00]);
    sembrarSucDoc('boletas', $t, ['cod_local' => 'S001', 'serie' => 'B001', 'correlativo' => 1, 'fecha_emision' => '2026-01-15',
        'client_tipo_doc' => '1', 'client_num_doc' => '44556677', 'client_razon_social' => 'JUAN',
        'mto_imp_venta' => 59.00, 'mto_igv' => 9.00, 'monto_pagado' => 0.00]);
    sembrarSucDoc('credit_notes', $t, ['cod_local' => 'S001', 'serie' => 'FC01', 'correlativo' => 1, 'fecha_emision' => '2026-01-20',
        'mto_imp_venta' => 59.00, 'mto_igv' => 9.00]);
    sembrarSucDoc('debit_notes', $t, ['cod_local' => 'S001', 'serie' => 'FD01', 'correlativo' => 1, 'fecha_emision' => '2026-01-25',
        'mto_imp_venta' => 23.60, 'mto_igv' => 3.60]);

    // Sucursal S002: 1 factura
    sembrarSucDoc('invoices', $t, ['cod_local' => 'S002', 'serie' => 'F002', 'correlativo' => 1, 'fecha_emision' => '2026-01-12',
        'client_num_doc' => '20222', 'client_razon_social' => 'CLIENTE B',
        'mto_imp_venta' => 236.00, 'mto_igv' => 36.00, 'monto_pagado' => 236.00]);

    $r = app(ReportService::class)->porSucursal($t, [
        'fecha_desde' => '2026-01-01',
        'fecha_hasta' => '2026-01-31',
    ]);

    expect($r['gran_total'])->toBe(377.60);

    expect($r['comparativo']->toArray())->toBe([
        [
            'sucursal_id' => $s1->id,
            'nombre' => 'Principal',
            'cod_local' => 'S001',
            'facturas' => ['cantidad' => 1, 'monto' => 118.00],
            'boletas' => ['cantidad' => 1, 'monto' => 59.00],
            'notas_credito' => ['cantidad' => 1, 'monto' => 59.00],
            'notas_debito' => ['cantidad' => 1, 'monto' => 23.60],
            'total_bruto' => 200.60,
            'neto' => 141.60,
            'igv' => 21.60,
            'cobrado' => 118.00,
            'pendiente' => 23.60,
            'ticket_promedio' => 100.30,
            'docs_dia_promedio' => 0.1,
            'porcentaje_total' => 37.5,
        ],
        [
            'sucursal_id' => $s2->id,
            'nombre' => 'Norte',
            'cod_local' => 'S002',
            'facturas' => ['cantidad' => 1, 'monto' => 236.00],
            'boletas' => ['cantidad' => 0, 'monto' => 0.00],
            'notas_credito' => ['cantidad' => 0, 'monto' => 0.00],
            'notas_debito' => ['cantidad' => 0, 'monto' => 0.00],
            'total_bruto' => 236.00,
            'neto' => 236.00,
            'igv' => 36.00,
            'cobrado' => 236.00,
            'pendiente' => 0.00,
            'ticket_promedio' => 236.00,
            'docs_dia_promedio' => 0.0,
            'porcentaje_total' => 62.5,
        ],
    ]);
});
