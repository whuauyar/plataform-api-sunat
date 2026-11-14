<?php

declare(strict_types=1);

use App\Models\Sucursal;
use App\Models\Tenant;
use App\Services\Reports\ReportService;
use Illuminate\Support\Facades\DB;

/**
 * Golden-master de ventasConsolidado: fija la salida EXACTA (cifras tributarias)
 * sobre un dataset determinista, para que cualquier refactor de rendimiento
 * (p. ej. no hidratar modelos con ->toBase()) tenga que devolver lo mismo.
 */
function sembrarDoc(string $tabla, Tenant $t, array $over): void
{
    DB::table($tabla)->insert(array_merge([
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
    ], $over));
}

test('ventasConsolidado devuelve la salida esperada sobre un dataset conocido', function () {
    $t = Tenant::factory()->create();
    Sucursal::create([
        'tenant_id' => $t->id,
        'cod_local' => 'S001',
        'nombre' => 'Principal',
        'direccion' => 'AV. PRINCIPAL 123',
        'ubigeo' => '150101',
        'is_active' => true,
    ]);

    // Facturas (01)
    sembrarDoc('invoices', $t, ['serie' => 'F001', 'correlativo' => 1, 'fecha_emision' => '2026-01-15',
        'client_num_doc' => '20111', 'client_razon_social' => 'CLIENTE A', 'tipo_moneda' => 'PEN',
        'mto_imp_venta' => 118.00, 'mto_igv' => 18.00, 'monto_pagado' => 118.00]);
    sembrarDoc('invoices', $t, ['serie' => 'F001', 'correlativo' => 2, 'fecha_emision' => '2026-02-10',
        'client_num_doc' => '20222', 'client_razon_social' => 'CLIENTE B', 'tipo_moneda' => 'USD',
        'mto_imp_venta' => 236.00, 'mto_igv' => 36.00, 'monto_pagado' => 0.00]);

    // Boleta (03)
    sembrarDoc('boletas', $t, ['serie' => 'B001', 'correlativo' => 1, 'fecha_emision' => '2026-01-20',
        'client_tipo_doc' => '1', 'client_num_doc' => '44556677', 'client_razon_social' => 'JUAN', 'tipo_moneda' => 'PEN',
        'mto_imp_venta' => 59.00, 'mto_igv' => 9.00, 'monto_pagado' => 59.00]);

    $notaExtra = [
        'doc_afectado_tipo' => '01', 'doc_afectado_serie' => 'F001', 'doc_afectado_correlativo' => '1',
        'cod_motivo' => '01', 'des_motivo' => 'ANULACION DE LA OPERACION',
    ];

    // Nota de crédito (07)
    sembrarDoc('credit_notes', $t, [...$notaExtra, 'serie' => 'FC01', 'correlativo' => 1, 'fecha_emision' => '2026-02-05',
        'client_num_doc' => '20111', 'client_razon_social' => 'CLIENTE A', 'tipo_moneda' => 'PEN',
        'mto_imp_venta' => 118.00, 'mto_igv' => 18.00]);

    // Nota de débito (08)
    sembrarDoc('debit_notes', $t, [...$notaExtra, 'serie' => 'FD01', 'correlativo' => 1, 'fecha_emision' => '2026-02-15',
        'client_num_doc' => '20222', 'client_razon_social' => 'CLIENTE B', 'tipo_moneda' => 'USD',
        'mto_imp_venta' => 23.60, 'mto_igv' => 3.60]);

    $r = app(ReportService::class)->ventasConsolidado($t, [
        'fecha_desde' => '2026-01-01',
        'fecha_hasta' => '2026-02-28',
        'agrupacion' => 'mes',
    ]);

    // KPIs
    expect($r['kpis'])->toMatchArray([
        'total_facturado' => 436.60,
        'total_igv' => 48.60,
        'docs_emitidos' => 5,
        'ticket_promedio' => 145.53,
        'nc_emitidas' => 1,
        'nd_emitidas' => 1,
        'venta_neta' => 318.60,
        'total_cobrado' => 177.00,
        'porcentaje_cobrado' => 40.5,
    ]);

    // Desglose por tipo
    expect($r['desglose_por_tipo'])->toBe([
        ['tipo' => '01', 'nombre' => 'Facturas', 'cantidad' => 2, 'monto' => 354.00],
        ['tipo' => '03', 'nombre' => 'Boletas', 'cantidad' => 1, 'monto' => 59.00],
        ['tipo' => '07', 'nombre' => 'Notas de Crédito', 'cantidad' => 1, 'monto' => 118.00],
        ['tipo' => '08', 'nombre' => 'Notas de Débito', 'cantidad' => 1, 'monto' => 23.60],
    ]);

    // Desglose por sucursal
    expect($r['desglose_por_sucursal'])->toBe([
        ['sucursal' => 'Principal', 'cod_local' => 'S001', 'facturas' => 2, 'boletas' => 1, 'nc' => 1, 'nd' => 1, 'monto' => 318.60],
    ]);

    // Top clientes (ordenado desc por monto_bruto; solo facturas+boletas).
    // num_doc llega como int: es la clave de groupBy y PHP castea claves numéricas.
    expect($r['top_clientes']->toArray())->toBe([
        ['num_doc' => 20222, 'razon_social' => 'CLIENTE B', 'cantidad_docs' => 1, 'monto_bruto' => 236.00],
        ['num_doc' => 20111, 'razon_social' => 'CLIENTE A', 'cantidad_docs' => 1, 'monto_bruto' => 118.00],
        ['num_doc' => 44556677, 'razon_social' => 'JUAN', 'cantidad_docs' => 1, 'monto_bruto' => 59.00],
    ]);

    // Desglose por moneda (facturas+boletas)
    $moneda = collect($r['desglose_por_moneda']->toArray())->keyBy('moneda');
    expect($moneda['PEN'])->toBe(['moneda' => 'PEN', 'cantidad' => 2, 'monto' => 177.00]);
    expect($moneda['USD'])->toBe(['moneda' => 'USD', 'cantidad' => 1, 'monto' => 236.00]);

    // Desglose temporal (por mes, ordenado)
    expect($r['desglose_temporal'])->toBe([
        ['periodo' => '2026-01', 'facturas' => 1, 'facturas_monto' => 118.00, 'boletas' => 1, 'boletas_monto' => 59.00,
            'nc' => 0, 'nc_monto' => 0.00, 'nd' => 0, 'nd_monto' => 0.00, 'neto' => 177.00, 'igv' => 27.00],
        ['periodo' => '2026-02', 'facturas' => 1, 'facturas_monto' => 236.00, 'boletas' => 0, 'boletas_monto' => 0.00,
            'nc' => 1, 'nc_monto' => 118.00, 'nd' => 1, 'nd_monto' => 23.60, 'neto' => 141.60, 'igv' => 57.60],
    ]);
});
