<?php

declare(strict_types=1);

use App\Jobs\SendRetentionToSunat;
use App\Models\Retention;
use App\Models\Tenant;
use App\Services\Greenter\GreenterService;
use Illuminate\Support\Facades\DB;

/**
 * Guarda de idempotencia: un comprobante ya en estado final (aceptado/anulado) NO se
 * reenvía a SUNAT aunque su job se vuelva a despachar (reenvío manual o reencolado).
 */
test('el job no reenvía una retención ya aceptada', function () {
    $tenant = Tenant::factory()->create();

    // Si la guarda fallara, el job intentaría construir el servicio y enviar: lo
    // detectamos exigiendo que GreenterService NUNCA se instancie/use.
    $spy = Mockery::mock(GreenterService::class);
    $spy->shouldReceive('send')->never();
    app()->bind(GreenterService::class, fn () => $spy);

    $id = DB::table('retentions')->insertGetId([
        'tenant_id' => $tenant->id,
        'serie' => 'R001',
        'correlativo' => 1,
        'fecha_emision' => now(),
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100000001',
        'proveedor_razon_social' => 'PROVEEDOR SAC',
        'regimen' => '01',
        'tasa' => 3.00,
        'sunat_status' => 'aceptado',
        'sunat_code' => '0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SendRetentionToSunat($id))->handle();

    $r = Retention::find($id);
    expect($r->sunat_status)->toBe('aceptado');
    expect($r->sunat_code)->toBe('0');
});
