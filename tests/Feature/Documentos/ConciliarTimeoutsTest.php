<?php

declare(strict_types=1);

use App\Actions\Documents\ConsultCdrAction;
use App\Models\Retention;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Inserta una retención en pendiente/SUNAT_TIMEOUT y devuelve su id. */
function retencionEnTimeout(Tenant $tenant): int
{
    return DB::table('retentions')->insertGetId([
        'tenant_id' => $tenant->id,
        'serie' => 'R001',
        'correlativo' => 1,
        'fecha_emision' => now(),
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100000001',
        'proveedor_razon_social' => 'PROVEEDOR SAC',
        'regimen' => '01',
        'tasa' => 3.00,
        'sunat_status' => 'pendiente',
        'sunat_code' => 'SUNAT_TIMEOUT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Mockea la consulta de CDR para no tocar la red. */
function fakeConsulta(array $respuesta): void
{
    $mock = Mockery::mock(ConsultCdrAction::class);
    $mock->shouldReceive('execute')->andReturn($respuesta);
    app()->instance(ConsultCdrAction::class, $mock);
}

test('un SUNAT_TIMEOUT que SUNAT sí aceptó se resuelve a aceptado (sin reenviar)', function () {
    $tenant = Tenant::factory()->create();
    $id = retencionEnTimeout($tenant);
    fakeConsulta(['success' => true, 'code' => '0', 'accepted' => true, 'description' => 'La Retención...fue aceptada']);

    $this->artisan('sunat:conciliar-timeouts')->assertSuccessful();

    $r = Retention::find($id);
    expect($r->sunat_status)->toBe('aceptado');
    expect($r->sunat_code)->toBe('0');
});

test('un SUNAT_TIMEOUT con CDR de rechazo real se marca rechazado', function () {
    $tenant = Tenant::factory()->create();
    $id = retencionEnTimeout($tenant);
    fakeConsulta(['success' => true, 'code' => '2000', 'description' => 'El comprobante fue rechazado']);

    $this->artisan('sunat:conciliar-timeouts')->assertSuccessful();

    $r = Retention::find($id);
    expect($r->sunat_status)->toBe('rechazado');
    expect($r->sunat_code)->toBe('2000');
});

test('si SUNAT aún no responde, el comprobante sigue por confirmar (no se toca)', function () {
    $tenant = Tenant::factory()->create();
    $id = retencionEnTimeout($tenant);
    fakeConsulta(['success' => false]);

    $this->artisan('sunat:conciliar-timeouts')->assertSuccessful();

    $r = Retention::find($id);
    expect($r->sunat_status)->toBe('pendiente');
    expect($r->sunat_code)->toBe('SUNAT_TIMEOUT');
});
