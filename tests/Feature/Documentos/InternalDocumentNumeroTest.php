<?php

declare(strict_types=1);

use App\Models\InternalDocument;
use App\Models\Tenant;

/**
 * generateNumero() debe continuar el correlativo por orden NUMÉRICO (no de texto)
 * y por empresa + tipo. Corre en la base del CI (SQLite); la misma consulta se
 * validó contra PostgreSQL 16 real, donde antes fallaba por `AS UNSIGNED`.
 */
function documentoInterno(Tenant $tenant, string $tipo, string $numero): InternalDocument
{
    return InternalDocument::create([
        'tenant_id' => $tenant->id,
        'type' => $tipo,
        'numero' => $numero,
        'fecha_emision' => now()->toDateString(),
        'client_tipo_doc' => '1',
        'client_num_doc' => '12345678',
        'client_razon_social' => 'CLIENTE DE PRUEBA',
    ]);
}

test('sin documentos previos empieza en 000001', function () {
    $tenant = Tenant::factory()->create();
    $periodo = now()->format('Ym');

    expect(InternalDocument::generateNumero($tenant->id, 'quotation'))->toBe("COT-{$periodo}-000001");
});

test('continua desde el mayor numerico y no desde el mayor alfabetico', function () {
    $tenant = Tenant::factory()->create();
    $periodo = now()->format('Ym');

    // Por texto, '999999' > '1000000'; por número, 1000000 es el mayor.
    documentoInterno($tenant, 'quotation', "COT-{$periodo}-999999");
    documentoInterno($tenant, 'quotation', "COT-{$periodo}-1000000");

    expect(InternalDocument::generateNumero($tenant->id, 'quotation'))->toBe("COT-{$periodo}-1000001");
});

test('cada tipo y cada empresa llevan su propio correlativo', function () {
    $empresa = Tenant::factory()->create();
    $otraEmpresa = Tenant::factory()->create();
    $periodo = now()->format('Ym');

    documentoInterno($empresa, 'quotation', "COT-{$periodo}-000007");
    documentoInterno($otraEmpresa, 'sale_note', "NV-{$periodo}-000050");

    expect(InternalDocument::generateNumero($empresa->id, 'quotation'))->toBe("COT-{$periodo}-000008")
        ->and(InternalDocument::generateNumero($empresa->id, 'sale_note'))->toBe("NV-{$periodo}-000001")
        ->and(InternalDocument::generateNumero($otraEmpresa->id, 'sale_note'))->toBe("NV-{$periodo}-000051");
});
