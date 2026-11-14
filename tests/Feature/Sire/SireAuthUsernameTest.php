<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Sire\Services\Auth\SireAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Bug: SUNAT devolvía access_denied "Error en la autenticacion del usuario" al
 * autenticar SIRE porque el username se enviaba como "{RUC} {USUARIO}" (con
 * espacio). SUNAT lo exige CONCATENADO: {RUC}{USUARIO} sin separador.
 */
test('el username SIRE se envía concatenado RUC+usuario, sin espacio', function () {
    $historial = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'tok-123',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ])),
    ]));
    $stack->push(Middleware::history($historial));

    $tenant = new Tenant;
    $tenant->id = 999;
    $tenant->ruc = '20123456789';
    $tenant->sol_user = 'MODDATOS';
    $tenant->sol_pass = 'clave-sol';
    $tenant->sire_client_id = 'CID';
    $tenant->sire_client_secret = 'CSEC';

    Cache::flush();

    $token = (new SireAuthService(new Client(['handler' => $stack])))->getToken($tenant);

    expect($token)->toBe('tok-123');

    parse_str((string) $historial[0]['request']->getBody(), $params);
    expect($params['username'])->toBe('20123456789MODDATOS')
        ->and($params['password'])->toBe('clave-sol');
});
