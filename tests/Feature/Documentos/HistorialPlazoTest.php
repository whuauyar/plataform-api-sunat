<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Documents\DocumentoListingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function insertarFactura(Tenant $t, int $correlativo, string $fechaEmision, string $estado = 'pendiente'): void
{
    DB::table('invoices')->insert([
        'tenant_id' => $t->id,
        'serie' => 'F001',
        'correlativo' => $correlativo,
        'fecha_emision' => $fechaEmision,
        'client_tipo_doc' => '6',
        'client_num_doc' => '20131312955',
        'client_razon_social' => 'CLIENTE SAC',
        'sunat_status' => $estado,
        'mto_imp_venta' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string,?string> numero => plazo_envio */
function plazoPorNumero(Tenant $t): array
{
    $data = app(DocumentoListingService::class)->listar($t, 'facturas', []);

    return collect($data)->mapWithKeys(fn ($r) => [$r['numero'] => $r['plazo_envio']])->all();
}

test('marca por_vencer, vencido y null según el plazo y el estado', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    $t = Tenant::factory()->create();

    insertarFactura($t, 1, Carbon::today()->subDays(2)->toDateString());             // límite hoy+1 → por_vencer
    insertarFactura($t, 2, Carbon::today()->subDays(5)->toDateString());             // límite hoy-2 → vencido
    insertarFactura($t, 3, Carbon::today()->toDateString());                         // límite hoy+3 → null
    insertarFactura($t, 4, Carbon::today()->subDays(2)->toDateString(), 'aceptado'); // final → null

    $plazo = plazoPorNumero($t);

    expect($plazo['F001-00000001'])->toBe('por_vencer');
    expect($plazo['F001-00000002'])->toBe('vencido');
    expect($plazo['F001-00000003'])->toBeNull();
    expect($plazo['F001-00000004'])->toBeNull();
});

test('las boletas no se marcan por plazo (van por resumen diario)', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    $t = Tenant::factory()->create();

    DB::table('boletas')->insert([
        'tenant_id' => $t->id, 'serie' => 'B001', 'correlativo' => 1,
        'fecha_emision' => Carbon::today()->subDays(5)->toDateString(),
        'client_tipo_doc' => '1', 'client_num_doc' => '44556677', 'client_razon_social' => 'JUAN',
        'sunat_status' => 'pendiente', 'mto_imp_venta' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = app(DocumentoListingService::class)->listar($t, 'boletas', []);

    expect($data[0]['plazo_envio'])->toBeNull();
});

test('marca fuera_de_plazo un aceptado enviado después del plazo (extemporáneo)', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    $t = Tenant::factory()->create();

    $base = [
        'tenant_id' => $t->id, 'serie' => 'F001',
        'client_tipo_doc' => '6', 'client_num_doc' => '20131312955', 'client_razon_social' => 'CLIENTE SAC',
        'sunat_status' => 'aceptado', 'mto_imp_venta' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ];

    // Emitida el día 1, enviada/aceptada el día 10 → límite día 4 → extemporánea.
    DB::table('invoices')->insert([...$base,
        'correlativo' => 1,
        'fecha_emision' => Carbon::today()->subDays(10)->toDateString(),
        'sent_at' => Carbon::today()->subDay()->toDateTimeString(),
    ]);
    // Emitida y aceptada dentro del plazo (mismo día) → al día.
    DB::table('invoices')->insert([...$base,
        'correlativo' => 2,
        'fecha_emision' => Carbon::today()->subDays(2)->toDateString(),
        'sent_at' => Carbon::today()->subDays(2)->toDateTimeString(),
    ]);

    $plazo = plazoPorNumero($t);

    expect($plazo['F001-00000001'])->toBe('fuera_de_plazo');
    expect($plazo['F001-00000002'])->toBeNull();
});
