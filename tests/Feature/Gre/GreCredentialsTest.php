<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Greenter\GreenterService;

/**
 * Bug: las guías (GRE) daban 401 "unauthorized_client" porque el panel guarda
 * las credenciales de la API SUNAT en sire_client_id/secret, pero GreenterService
 * leía SOLO client_id/secret → nunca usaba lo cargado en el panel y caía al
 * default del config. Ahora resuelve: client_id → sire_client_id → config.
 */
function greCreds(Tenant $t): array
{
    $svc = new GreenterService($t);
    $m = (new ReflectionClass($svc))->getMethod('greCredentials');
    $m->setAccessible(true);

    return $m->invoke($svc);
}

test('usa las credenciales del panel (sire_*) cuando client_id está vacío', function () {
    $t = new Tenant();
    $t->environment = 'production';
    $t->sire_client_id = 'PANEL-CLIENT-ID';
    $t->sire_client_secret = 'PANEL-SECRET';

    expect(greCreds($t))->toBe(['PANEL-CLIENT-ID', 'PANEL-SECRET']);
});

test('client_id/secret propios tienen prioridad sobre sire_*', function () {
    $t = new Tenant();
    $t->environment = 'production';
    $t->client_id = 'OWN-ID';
    $t->client_secret = 'OWN-SECRET';
    $t->sire_client_id = 'PANEL-CLIENT-ID';
    $t->sire_client_secret = 'PANEL-SECRET';

    expect(greCreds($t))->toBe(['OWN-ID', 'OWN-SECRET']);
});

test('sin credenciales propias ni de panel, cae al default del config (beta)', function () {
    $t = new Tenant();
    $t->environment = 'beta';

    expect(greCreds($t))->toBe([
        config('facturacion.sunat.beta.gre_client_id'),
        config('facturacion.sunat.beta.gre_client_secret'),
    ]);
});
