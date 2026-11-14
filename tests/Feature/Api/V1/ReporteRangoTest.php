<?php

declare(strict_types=1);

use App\Models\Tenant;

/**
 * Tope de rango de fechas en los reportes (C4): evita que un rango enorme cargue
 * todo el histórico del tenant en memoria. Configurable vía reportes.max_rango_dias.
 */
function reporteHeaders(Tenant $t): array
{
    return [
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ];
}

test('el reporte rechaza un rango mayor al tope con 422 accionable', function () {
    $tenant = Tenant::factory()->create();

    $res = $this->getJson(
        '/api/v1/reportes/registro-ventas?fecha_desde=2024-01-01&fecha_hasta=2026-01-01',
        reporteHeaders($tenant),
    );

    $res->assertStatus(422)->assertJsonPath('estado', 'error');
    expect($res->json('errores'))->toHaveKey('fecha_hasta');
});

test('el reporte acepta un rango dentro del tope', function () {
    $tenant = Tenant::factory()->create();

    $this->getJson(
        '/api/v1/reportes/registro-ventas?fecha_desde=2026-01-01&fecha_hasta=2026-01-31',
        reporteHeaders($tenant),
    )->assertOk();
});

test('el tope es configurable: un rango antes válido se rechaza al bajarlo', function () {
    config(['reportes.max_rango_dias' => 7]);
    $tenant = Tenant::factory()->create();

    $res = $this->getJson(
        '/api/v1/reportes/registro-ventas?fecha_desde=2026-01-01&fecha_hasta=2026-01-31',
        reporteHeaders($tenant),
    );

    $res->assertStatus(422);
    expect($res->json('errores'))->toHaveKey('fecha_hasta');
});

test('tope 0 desactiva el límite (compatibilidad hacia atrás)', function () {
    config(['reportes.max_rango_dias' => 0]);
    $tenant = Tenant::factory()->create();

    $this->getJson(
        '/api/v1/reportes/registro-ventas?fecha_desde=2000-01-01&fecha_hasta=2026-01-01',
        reporteHeaders($tenant),
    )->assertOk();
});
