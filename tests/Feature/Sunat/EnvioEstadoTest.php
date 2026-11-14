<?php

declare(strict_types=1);

use App\Jobs\SendDocumentToSunat;
use App\Models\Boleta;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

function crearBoleta(Tenant $t, string $estado, int $corr): Boleta
{
    $b = new Boleta();
    $b->forceFill([
        'tenant_id' => $t->id,
        'serie' => 'B001',
        'correlativo' => $corr,
        'fecha_emision' => now()->toDateString(),
        'client_tipo_doc' => '1',
        'client_num_doc' => '12345678',
        'client_razon_social' => 'CLIENTE PRUEBA',
        'sunat_status' => $estado,
    ]);
    $b->save();

    // Envejecer para pasar la ventana de seguridad del comando (updated_at > 15 min).
    Boleta::whereKey($b->id)->update([
        'updated_at' => now()->subMinutes(30),
        'created_at' => now()->subDay(),
    ]);

    return $b->fresh();
}

test('el reintento reencola los "enviado" pero NO los "pendiente" (resumen/manual)', function () {
    Queue::fake();
    $t = Tenant::factory()->create();

    crearBoleta($t, 'enviado', 1);     // atascado → se recupera
    crearBoleta($t, 'pendiente', 2);   // intencional (resumen/manual) → NO tocar

    $this->artisan('sunat:reintentar-pendientes')->assertSuccessful();

    // Solo el "enviado" se reencola.
    Queue::assertPushed(SendDocumentToSunat::class, 1);
});

test('SendDocumentToSunat NO reenvía un comprobante ya aceptado (idempotencia)', function () {
    $t = Tenant::factory()->create();
    $b = crearBoleta($t, 'aceptado', 10);

    // Ejecuta el job directamente: el guard debe retornar sin llamar a SUNAT.
    (new SendDocumentToSunat(Boleta::class, $b->id))->handle();

    expect($b->fresh()->sunat_status)->toBe('aceptado');
});
