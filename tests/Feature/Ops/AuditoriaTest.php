<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Tenant;

function headersAudit(Tenant $t, array $extra = []): array
{
    return array_merge([
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ], $extra);
}

test('regenerar credenciales devuelve un secret que SÍ autentica (fix del bug) y queda auditado', function () {
    $tenant = Tenant::factory()->create();

    $r = $this->postJson('/api/v1/empresa/credenciales/regenerar', [], headersAudit($tenant));
    $r->assertOk();

    $nuevaKey = $r->json('datos.api_key');
    $nuevoSecret = $r->json('datos.api_secret');
    expect($nuevaKey)->not->toBeNull()->and($nuevoSecret)->not->toBeNull();

    // El secret devuelto debe autenticar (antes se guardaba su hash → rompía).
    $this->getJson('/api/v1/clientes', [
        'X-Api-Key' => $nuevaKey,
        'X-Api-Secret' => $nuevoSecret,
        'Accept' => 'application/json',
    ])->assertOk();

    // El secret viejo ya no sirve.
    $this->getJson('/api/v1/clientes', headersAudit($tenant))->assertStatus(401);

    // Quedó registrado en la bitácora, como actor 'api'.
    $log = AuditLog::where('accion', 'credenciales.regeneradas')
        ->where('tenant_id', $tenant->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_tipo)->toBe('api');
});

test('actualizar la empresa por API queda auditado', function () {
    $tenant = Tenant::factory()->create();

    $this->putJson('/api/v1/empresa', ['razon_social' => 'NUEVO NOMBRE SAC'], headersAudit($tenant))
        ->assertOk();

    expect(
        AuditLog::where('accion', 'empresa.actualizada')->where('tenant_id', $tenant->id)->exists()
    )->toBeTrue();
});

test('AuditLog::registrar nunca lanza aunque falte contexto', function () {
    AuditLog::registrar('prueba.accion', ['descripcion' => 'sin request de usuario ni tenant']);

    expect(AuditLog::where('accion', 'prueba.accion')->exists())->toBeTrue();
});
