<?php

declare(strict_types=1);

use App\Models\Tenant;

function fpHeaders(Tenant $t): array
{
    return [
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ];
}

dataset('endpoints', ['/api/v1/facturas', '/api/v1/boletas']);
dataset('formas_validas', ['Contado', 'Credito', 'Tarjeta', 'Billetera Digital', 'Deposito', 'Transferencia']);

test('acepta formas de pago del sistema sin error en forma_pago', function (string $endpoint, string $forma) {
    $tenant = Tenant::factory()->create();

    // Payload mínimo: fallará por otros campos requeridos, pero NO por forma_pago.
    $res = $this->postJson($endpoint, ['forma_pago' => $forma], fpHeaders($tenant));

    $res->assertStatus(422);
    expect($res->json('errores.forma_pago'))->toBeNull();
})->with('endpoints')->with('formas_validas');

test('rechaza una forma de pago no permitida', function (string $endpoint) {
    $tenant = Tenant::factory()->create();

    $res = $this->postJson($endpoint, ['forma_pago' => 'ValorInventado'], fpHeaders($tenant));

    $res->assertStatus(422);
    expect($res->json('errores.forma_pago'))->not->toBeNull();
})->with('endpoints');
