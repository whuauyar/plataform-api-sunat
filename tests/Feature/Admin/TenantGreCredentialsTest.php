<?php

declare(strict_types=1);

use App\Http\Requests\Admin\TenantRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Bug reportado: al editar una empresa, Client ID / Client Secret (credenciales
 * GRE) no se guardaban. Causa: TenantRequest no tenía reglas para client_id /
 * client_secret y el controller guarda con $request->validated(), que descarta
 * cualquier campo sin regla. Este test prueba el mecanismo del fix sin depender
 * de la autenticación del panel.
 */
test('TenantRequest ahora valida client_id y client_secret (antes se descartaban)', function () {
    $rules = TenantRequest::create('/admin/empresas/1', 'PUT', [])->rules();

    // 1) Las reglas existen (antes no estaban → validated() los eliminaba).
    expect($rules)->toHaveKey('client_id')->toHaveKey('client_secret');

    // 2) Con esas reglas, los valores pasan la validación y quedan en validated().
    $validator = Validator::make(
        ['client_id' => 'GRE-CLIENT-ID-123', 'client_secret' => 'gre-secret-xyz'],
        ['client_id' => $rules['client_id'], 'client_secret' => $rules['client_secret']],
    );

    expect($validator->passes())->toBeTrue()
        ->and($validator->validated())->toBe([
            'client_id' => 'GRE-CLIENT-ID-123',
            'client_secret' => 'gre-secret-xyz',
        ]);
});
