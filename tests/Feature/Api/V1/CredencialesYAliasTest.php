<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * R1 — El secreto devuelto por la recuperación de credenciales debe autenticar.
 * Antes se guardaba hash('sha256', secreto) pero se devolvía el secreto crudo;
 * como ResolveTenant compara el api_secret descifrado contra el crudo, el
 * secreto recuperado NUNCA autenticaba (401 perpetuo).
 */
test('el secreto recuperado por /credenciales/recuperar/verificar SÍ autentica', function () {
    $tenant = Tenant::factory()->create();

    // Simula un token de recuperación válido (lo normal lo emite 'solicitar' por email).
    $token = Str::random(64);
    Cache::put("credential_recovery:{$token}", $tenant->id, now()->addMinutes(30));

    $resp = $this->postJson('/api/v1/credenciales/recuperar/verificar', ['token' => $token])
        ->assertOk();

    $apiKey = $resp->json('datos.api_key');
    $apiSecret = $resp->json('datos.api_secret');

    expect($apiKey)->not->toBeEmpty();
    expect($apiSecret)->not->toBeEmpty();

    // El secreto guardado (descifrado por el cast) debe ser EXACTAMENTE el devuelto.
    expect($tenant->fresh()->api_secret)->toBe($apiSecret);

    // Y debe autenticar contra una ruta protegida (antes del fix daba 401).
    $this->withHeaders([
        'X-Api-Key' => $apiKey,
        'X-Api-Secret' => $apiSecret,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/empresa')->assertOk();
});

/**
 * R2 — El alias /api/sunat/* no debe permitir saltar idempotencia ni el cupo.
 * Verificamos que las rutas de emisión del alias lleven el stack completo.
 */
test('el alias /sunat aplica idempotencia, licencia y cupo en los POST de emisión', function () {
    $rutas = collect(Route::getRoutes()->getRoutes());

    $postFacturas = $rutas->first(fn ($r) => $r->uri() === 'api/sunat/facturas' && in_array('POST', $r->methods(), true));
    $postBoletas = $rutas->first(fn ($r) => $r->uri() === 'api/sunat/boletas' && in_array('POST', $r->methods(), true));

    expect($postFacturas)->not->toBeNull();
    expect($postBoletas)->not->toBeNull();

    foreach ([$postFacturas, $postBoletas] as $ruta) {
        $mw = $ruta->gatherMiddleware();
        expect($mw)->toContain('resolve.tenant');
        expect($mw)->toContain('idempotency');
        expect($mw)->toContain('license');
        expect($mw)->toContain('check.limit:sunat');
    }
});

test('el GET del alias /sunat/configuracion queda bajo idempotencia pero sin license/cupo', function () {
    $rutas = collect(Route::getRoutes()->getRoutes());
    $getConfig = $rutas->first(fn ($r) => $r->uri() === 'api/sunat/configuracion' && in_array('GET', $r->methods(), true));

    expect($getConfig)->not->toBeNull();
    $mw = $getConfig->gatherMiddleware();
    expect($mw)->toContain('idempotency');
    // Un GET de solo lectura no debe exigir licencia ni consumir cupo.
    expect($mw)->not->toContain('check.limit:sunat');
});
