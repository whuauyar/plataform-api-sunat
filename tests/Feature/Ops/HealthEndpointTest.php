<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::forget('ops:health'));

test('GET /api/health responde 200 y lista los componentes (sin autenticación)', function () {
    $res = $this->getJson('/api/health');

    $res->assertOk()
        ->assertJsonStructure([
            'estado',
            'timestamp',
            'checks' => [['componente', 'estado', 'detalle']],
        ])
        ->assertJsonPath('estado', fn ($e) => in_array($e, ['ok', 'degradado'], true));

    // La BD debe aparecer como un componente chequeado.
    expect(collect($res->json('checks'))->pluck('componente'))->toContain('Base de datos');
});

test('el health no expone datos sensibles (solo componente/estado/detalle)', function () {
    $res = $this->getJson('/api/health');

    foreach ($res->json('checks') as $check) {
        expect(array_keys($check))->toEqualCanonicalizing(['componente', 'estado', 'detalle']);
    }
});
