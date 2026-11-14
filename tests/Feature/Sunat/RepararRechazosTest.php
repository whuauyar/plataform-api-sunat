<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Tenant;

function facturaAceptada(Tenant $t, int $corr, ?string $code): Invoice
{
    $inv = new Invoice;
    $inv->forceFill([
        'tenant_id' => $t->id,
        'serie' => 'F002',
        'correlativo' => $corr,
        'fecha_emision' => now()->toDateString(),
        'client_tipo_doc' => '6',
        'client_num_doc' => '20123456789',
        'client_razon_social' => 'GRUPO COMERCIAL',
        'sunat_status' => 'aceptado',
        'sunat_code' => $code,
    ])->save();

    return $inv;
}

test('comprobantes:reparar-rechazos marca rechazado un aceptado con código 2108', function () {
    $t = Tenant::factory()->create();
    $rechazo = facturaAceptada($t, 206, '2108'); // debe pasar a rechazado
    $ok = facturaAceptada($t, 207, '0');          // aceptado real → se queda
    $obs = facturaAceptada($t, 208, '4000');      // observación → se queda aceptado

    $this->artisan('comprobantes:reparar-rechazos')->assertExitCode(0);

    expect($rechazo->refresh()->sunat_status)->toBe('rechazado')
        ->and($ok->refresh()->sunat_status)->toBe('aceptado')
        ->and($obs->refresh()->sunat_status)->toBe('aceptado');
});

test('el dry-run no modifica nada', function () {
    $t = Tenant::factory()->create();
    $f = facturaAceptada($t, 300, '2335');

    $this->artisan('comprobantes:reparar-rechazos --dry-run')->assertExitCode(0);

    expect($f->refresh()->sunat_status)->toBe('aceptado');
});
