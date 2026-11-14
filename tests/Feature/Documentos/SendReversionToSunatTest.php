<?php

use App\Jobs\SendReversionToSunat;
use App\Models\Retention;
use App\Models\Tenant;
use App\Models\VoidedDocument;
use App\Services\Greenter\GreenterService;

function reversionPendiente(Tenant $tenant): array
{
    $retencion = Retention::create([
        'tenant_id' => $tenant->id,
        'serie' => 'R001',
        'correlativo' => 3,
        'fecha_emision' => '2026-09-23',
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100070970',
        'proveedor_razon_social' => 'PROVEEDOR SAC',
        'regimen' => '01',
        'tasa' => 3,
        'imp_retenido' => 35.4,
        'imp_pagado' => 1144.6,
        'sunat_status' => 'anulacion_en_proceso',
    ]);

    $reversion = VoidedDocument::create([
        'tenant_id' => $tenant->id,
        'identifier' => 'RR-20260924-001',
        'correlativo' => '001',
        'fecha_generacion' => '2026-09-23',
        'fecha_comunicacion' => '2026-09-24',
        'total_documentos' => 1,
        'detalles' => [['tipo_documento' => '20', 'serie' => 'R001', 'correlativo' => '3', 'motivo' => 'Error']],
        'sunat_status' => 'enviado',
    ]);

    return [$retencion, $reversion];
}

function simularSunat(array $resultado): void
{
    $servicio = Mockery::mock(GreenterService::class);
    $servicio->shouldReceive('send')->once()->andReturn($resultado);
    app()->bind(GreenterService::class, fn () => $servicio);
}

test('una reversión rechazada devuelve la retención a aceptado para poder reintentarla', function () {
    $tenant = Tenant::factory()->create();
    [$retencion, $reversion] = reversionPendiente($tenant);
    simularSunat(['success' => false, 'xml' => null, 'error_code' => '0151', 'error_message' => 'El nombre del archivo ZIP es incorrecto']);

    (new SendReversionToSunat($reversion->id))->handle();

    expect($reversion->fresh())
        ->sunat_status->toBe('rechazado')
        ->sunat_code->toBe('0151')
        ->and($retencion->fresh()->sunat_status)->toBe('aceptado');
});

test('una reversión enviada deja la retención anulada y espera el ticket', function () {
    Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\Storage::fake('public');
    $tenant = Tenant::factory()->create();
    [$retencion, $reversion] = reversionPendiente($tenant);
    simularSunat(['success' => true, 'xml' => '<xml/>', 'ticket' => '1790286421925']);

    (new SendReversionToSunat($reversion->id))->handle();

    expect($reversion->fresh())
        ->ticket->toBe('1790286421925')
        ->and($retencion->fresh()->sunat_status)->toBe('anulado');
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\CheckVoidedTicketStatus::class);
});
