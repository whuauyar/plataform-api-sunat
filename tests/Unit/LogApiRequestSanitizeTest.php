<?php

declare(strict_types=1);

use App\Http\Middleware\LogApiRequest;

/** Invoca el saneador privado (mismo método para request y response). */
function sanear(array $data): array
{
    $m = new ReflectionMethod(LogApiRequest::class, 'sanitize');
    $m->setAccessible(true);

    return $m->invoke(new LogApiRequest, $data);
}

test('oculta secretos en claves variadas, incluso anidadas', function () {
    $out = sanear([
        'client_secret' => 'abc',
        'api_secret' => 'xyz',
        'sire_client_secret' => 'q',
        'access_token' => 't',
        'refresh_token' => 'r',
        'clave_sol' => 'c',
        'sol_pass' => 'p',
        'password' => 'w',
        'contrasena_certificado' => 'z',
        'api_key' => 'k',
        'anidado' => ['token' => 'deep', 'nombre' => 'Juan'],
    ]);

    foreach (['client_secret', 'api_secret', 'sire_client_secret', 'access_token', 'refresh_token', 'clave_sol', 'sol_pass', 'password', 'contrasena_certificado', 'api_key'] as $k) {
        expect($out[$k])->toBe('***', "la clave {$k} debería estar oculta");
    }

    expect($out['anidado']['token'])->toBe('***');
    expect($out['anidado']['nombre'])->toBe('Juan');
});

test('no sobre-redacta identificadores ni datos no sensibles', function () {
    $out = sanear([
        'client_id' => 'visible-id',
        'sire_client_id' => 'visible-id-2',
        'ruc' => '20123456789',
        'razon_social' => 'ACME SAC',
        'serie' => 'F001',
    ]);

    expect($out['client_id'])->toBe('visible-id');
    expect($out['sire_client_id'])->toBe('visible-id-2');
    expect($out['ruc'])->toBe('20123456789');
    expect($out['razon_social'])->toBe('ACME SAC');
    expect($out['serie'])->toBe('F001');
});
