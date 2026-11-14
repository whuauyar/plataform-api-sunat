<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\VoidedDocument;
use Illuminate\Support\Facades\Storage;

function headersDeTenant(Tenant $t): array
{
    return [
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ];
}

function crearAnulacion(Tenant $t, array $attrs = []): VoidedDocument
{
    $v = new VoidedDocument();
    $v->forceFill(array_merge([
        'tenant_id' => $t->id,
        'identifier' => 'RA-20260918-001',
        'correlativo' => '00001',
        'fecha_generacion' => '2026-09-18',
        'fecha_comunicacion' => '2026-09-18',
        'total_documentos' => 1,
        'sunat_status' => 'aceptado',
    ], $attrs));
    $v->save();

    return $v;
}

test('GET anulaciones/{id}/xml descarga el XML de la anulación', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->create();
    Storage::disk('public')->put('voided/ra.xml', '<Baja>contenido-xml</Baja>');
    $v = crearAnulacion($tenant, ['xml_path' => 'voided/ra.xml']);

    $res = $this->get("/api/v1/anulaciones/{$v->id}/xml", headersDeTenant($tenant));

    $res->assertOk();
    $res->assertHeader('Content-Type', 'application/xml');
    expect($res->getContent())->toContain('contenido-xml');
});

test('GET anulaciones/{id}/cdr descarga el CDR (zip)', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->create();
    Storage::disk('public')->put('voided/ra-cdr.zip', 'PK-zip-fake');
    $v = crearAnulacion($tenant, ['cdr_path' => 'voided/ra-cdr.zip']);

    $res = $this->get("/api/v1/anulaciones/{$v->id}/cdr", headersDeTenant($tenant));

    $res->assertOk();
    $res->assertHeader('Content-Type', 'application/zip');
    expect($res->getContent())->toContain('PK-zip-fake');
});

test('devuelve 404 cuando la anulación no tiene archivo', function () {
    Storage::fake('public');
    $tenant = Tenant::factory()->create();
    $v = crearAnulacion($tenant); // sin xml_path/cdr_path

    $this->get("/api/v1/anulaciones/{$v->id}/xml", headersDeTenant($tenant))->assertStatus(404);
    $this->get("/api/v1/anulaciones/{$v->id}/cdr", headersDeTenant($tenant))->assertStatus(404);
});

test('no permite descargar la anulación de otro tenant', function () {
    Storage::fake('public');
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    Storage::disk('public')->put('voided/otro.xml', '<x/>');
    $vB = crearAnulacion($tenantB, ['xml_path' => 'voided/otro.xml']);

    // El tenant A intenta bajar la anulación del tenant B → 404 (scoping por tenant).
    $this->get("/api/v1/anulaciones/{$vB->id}/xml", headersDeTenant($tenantA))->assertStatus(404);
});
