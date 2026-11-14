<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Documents\DocumentoListingService;
use Illuminate\Support\Facades\DB;

function sembrarBoletas(Tenant $t, int $n): void
{
    $filas = [];
    for ($i = 1; $i <= $n; $i++) {
        $filas[] = [
            'tenant_id' => $t->id, 'serie' => 'B001', 'correlativo' => $i,
            'fecha_emision' => now()->subDays($n - $i)->toDateString(),
            'client_tipo_doc' => '1', 'client_num_doc' => '44556677', 'client_razon_social' => 'CLIENTE '.$i,
            'sunat_status' => 'aceptado', 'mto_imp_venta' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('boletas')->insert($filas);
}

test('pagina en el servidor: todo el histórico es alcanzable', function () {
    $t = Tenant::factory()->create();
    sembrarBoletas($t, 40);

    $p = app(DocumentoListingService::class)->paginar($t, 'boletas', [], 25);

    expect($p->total())->toBe(40);
    expect($p->lastPage())->toBe(2);
    expect($p->perPage())->toBe(25);
    expect(count($p->items()))->toBe(25);
});

test('la búsqueda por número encuentra un correlativo antiguo', function () {
    $t = Tenant::factory()->create();
    sembrarBoletas($t, 40);

    // El correlativo 3 (antiguo) se encuentra por su número aunque no esté en la 1.ª página.
    $p = app(DocumentoListingService::class)->paginar($t, 'boletas', ['buscar' => 'B001-00000003'], 25);

    expect($p->total())->toBe(1);
    expect($p->items()[0]['numero'])->toBe('B001-00000003');
});

test('la búsqueda también funciona por el correlativo suelto', function () {
    $t = Tenant::factory()->create();
    sembrarBoletas($t, 40);

    $p = app(DocumentoListingService::class)->paginar($t, 'boletas', ['buscar' => '00000007'], 25);

    expect($p->total())->toBe(1);
    expect($p->items()[0]['numero'])->toBe('B001-00000007');
});
