<?php

declare(strict_types=1);

use App\Models\Retention;
use App\Models\Tenant;
use App\Models\VoidedDocument;
use App\Services\Documents\VoidedService;

/**
 * R5 — El anti-duplicados de reversiones (RR) debe detectar un duplicado aunque
 * el `detalles` sea un array de OBJETOS. El bug: ReversionController usaba
 * whereJsonContains sobre un objeto, que en PostgreSQL (@>) no encaja dentro de
 * un array → nunca bloqueaba duplicados en producción. Ahora reusa el filtro en
 * PHP de VoidedService (agnóstico del motor).
 */
function crearRetencionAceptada(Tenant $t, int $correlativo = 3): Retention
{
    return Retention::create([
        'tenant_id' => $t->id,
        'serie' => 'R001',
        'correlativo' => $correlativo,
        'fecha_emision' => '2026-09-23',
        'proveedor_tipo_doc' => '6',
        'proveedor_num_doc' => '20100070970',
        'proveedor_razon_social' => 'PROVEEDOR SAC',
        'regimen' => '01',
        'tasa' => 3,
        'imp_retenido' => 35.4,
        'imp_pagado' => 1144.6,
        'sunat_status' => 'aceptado',
    ]);
}

function crearReversionVigente(Tenant $t, string $serie = 'R001', string $correlativo = '3'): VoidedDocument
{
    return VoidedDocument::create([
        'tenant_id' => $t->id,
        'identifier' => 'RR-20260924-001',
        'correlativo' => '001',
        'fecha_generacion' => '2026-09-23',
        'fecha_comunicacion' => '2026-09-24',
        'total_documentos' => 1,
        'detalles' => [['tipo_documento' => '20', 'serie' => $serie, 'correlativo' => $correlativo, 'motivo' => 'Error']],
        'sunat_status' => 'enviado',
    ]);
}

test('existeReversionVigente encuentra el duplicado por detalle (objeto en array)', function () {
    $t = Tenant::factory()->create();
    crearReversionVigente($t, 'R001', '3');

    $svc = app(VoidedService::class);

    // Mismo documento → debe encontrarlo (incluso con el correlativo sin/ con ceros).
    expect($svc->existeReversionVigente($t->id, '20', 'R001', '3'))->not->toBeNull();
    expect($svc->existeReversionVigente($t->id, '20', 'R001', '003'))->not->toBeNull();

    // Otro correlativo / otra serie / otro tipo → no debe encontrar nada.
    expect($svc->existeReversionVigente($t->id, '20', 'R001', '4'))->toBeNull();
    expect($svc->existeReversionVigente($t->id, '20', 'R002', '3'))->toBeNull();
    expect($svc->existeReversionVigente($t->id, '40', 'R001', '3'))->toBeNull();
});

test('POST /reversiones rechaza una reversión duplicada (422)', function () {
    $t = Tenant::factory()->create();
    crearRetencionAceptada($t, 3);
    crearReversionVigente($t, 'R001', '3');

    $this->withHeaders([
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/reversiones', [
        'fecha_generacion' => '2026-09-25',
        'enviar_automatico' => false,
        'detalles' => [
            ['tipo_documento' => '20', 'serie' => 'R001', 'correlativo' => '3', 'motivo' => 'Error de digitación'],
        ],
    ])->assertStatus(422)
        ->assertJsonFragment(['estado' => 'error']);
});
