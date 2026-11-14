<?php

declare(strict_types=1);

use App\Jobs\CheckVoidedTicketStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\VoidedDocument;

function crearFactura(Tenant $t, string $serie, int $corr, string $estado): Invoice
{
    $inv = new Invoice;
    $inv->forceFill([
        'tenant_id' => $t->id,
        'serie' => $serie,
        'correlativo' => $corr,
        'fecha_emision' => now()->toDateString(),
        'client_tipo_doc' => '6',
        'client_num_doc' => '20123456789',
        'client_razon_social' => 'CLIENTE SAC',
        'sunat_status' => $estado,
    ])->save();

    return $inv;
}

function crearBaja(Tenant $t, string $identifier, string $serie, int $corr, string $estado): VoidedDocument
{
    return VoidedDocument::create([
        'tenant_id' => $t->id,
        'identifier' => $identifier,
        'correlativo' => '1',
        'fecha_generacion' => now()->toDateString(),
        'fecha_comunicacion' => now()->toDateString(),
        'total_documentos' => 1,
        'sunat_status' => $estado,
        'detalles' => [['tipo_documento' => '01', 'serie' => $serie, 'correlativo' => $corr]],
    ]);
}

// ── Comando de reparación ────────────────────────────────────────────────────

test('bajas:reparar-anulaciones anula la factura cuya baja fue aceptada pero quedó en aceptado', function () {
    $t = Tenant::factory()->create();
    $inv = crearFactura($t, 'F0A1', 396, 'aceptado');
    crearBaja($t, 'RA-20261007-001', 'F0A1', 396, 'aceptado');

    $this->artisan('bajas:reparar-anulaciones')->assertExitCode(0);

    expect($inv->refresh()->sunat_status)->toBe('anulado');
});

test('bajas:reparar-anulaciones es idempotente (no toca una factura ya anulada)', function () {
    $t = Tenant::factory()->create();
    $inv = crearFactura($t, 'F001', 1, 'anulado');
    crearBaja($t, 'RA-20261007-003', 'F001', 1, 'aceptado');

    $this->artisan('bajas:reparar-anulaciones')->assertExitCode(0);

    expect($inv->refresh()->sunat_status)->toBe('anulado');
});

// ── Lógica del job (updateOriginalDocuments) ────────────────────────────────

test('baja aceptada anula la factura SIN importar su estado actual (bug 2)', function () {
    $t = Tenant::factory()->create();
    $inv = crearFactura($t, 'F0A1', 500, 'aceptado'); // volvió a aceptado por baja duplicada rechazada
    $baja = crearBaja($t, 'RA-x-001', 'F0A1', 500, 'aceptado');

    $m = new ReflectionMethod(CheckVoidedTicketStatus::class, 'updateOriginalDocuments');
    $m->setAccessible(true);
    $m->invoke(new CheckVoidedTicketStatus($baja->id), $baja, 'anulado');

    expect($inv->refresh()->sunat_status)->toBe('anulado');
});

test('una baja RECHAZADA no des-anula una factura ya anulada por otra baja válida', function () {
    $t = Tenant::factory()->create();
    $inv = crearFactura($t, 'F0A1', 501, 'anulado');
    $baja = crearBaja($t, 'RA-x-002', 'F0A1', 501, 'rechazado');

    $m = new ReflectionMethod(CheckVoidedTicketStatus::class, 'updateOriginalDocuments');
    $m->setAccessible(true);
    $m->invoke(new CheckVoidedTicketStatus($baja->id), $baja, 'aceptado');

    expect($inv->refresh()->sunat_status)->toBe('anulado'); // sigue anulada
});

test('una baja rechazada sí revierte una factura que estaba anulacion_en_proceso', function () {
    $t = Tenant::factory()->create();
    $inv = crearFactura($t, 'F0A1', 502, 'anulacion_en_proceso');
    $baja = crearBaja($t, 'RA-x-003', 'F0A1', 502, 'rechazado');

    $m = new ReflectionMethod(CheckVoidedTicketStatus::class, 'updateOriginalDocuments');
    $m->setAccessible(true);
    $m->invoke(new CheckVoidedTicketStatus($baja->id), $baja, 'aceptado');

    expect($inv->refresh()->sunat_status)->toBe('aceptado');
});
