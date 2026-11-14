<?php

use Tests\Feature\Sire\SireTestHelper;

uses(SireTestHelper::class);

beforeEach(function () {
    $this->setUpSunatMock();
});

describe('POST /v1/sire/activar', function () {
    test('activa SIRE cuando SUNAT devuelve token válido', function () {
        $this->setUpSireTenant(sireEnabled: false);
        $this->mockAuthSuccess('real_token', 3600);

        $response = $this->postJson('/api/v1/sire/activar', [], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'exito');
        $response->assertJsonPath('datos.sire_enabled', true);
        $response->assertJsonPath('datos.ruc', '20100000001');
        expect($response->json('datos.token_preview'))->toStartWith('real_token');

        expect($this->tenant->refresh()->sire_enabled)->toBeTrue();
    });

    test('rechaza credenciales inválidas con 401', function () {
        $this->setUpSireTenant(sireEnabled: false);
        $this->mockAuthFailure();

        $response = $this->postJson('/api/v1/sire/activar', [], $this->headers());

        $response->assertStatus(401);
        $response->assertJsonPath('estado', 'error');
        expect(strtolower($response->json('mensaje')))->toContain('credenciales');
    });

    test('sin credenciales API = 401', function () {
        $this->setUpSireTenant();

        $response = $this->postJson('/api/v1/sire/activar');

        $response->assertStatus(401);
    });
});

describe('POST /v1/sire/desactivar', function () {
    test('desactiva SIRE e invalida cache', function () {
        $this->setUpSireTenant(sireEnabled: true);

        $response = $this->postJson('/api/v1/sire/desactivar', [], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('datos.sire_enabled', false);
        expect($this->tenant->refresh()->sire_enabled)->toBeFalse();
    });
});
