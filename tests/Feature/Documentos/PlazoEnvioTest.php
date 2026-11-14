<?php

declare(strict_types=1);

use App\Jobs\NotifyWebhookJob;
use App\Jobs\SendDocumentToSunat;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Inserta una factura con fecha de emisión y estado dados; devuelve su id. */
function facturaEn(Tenant $t, int $correlativo, string $fechaEmision, string $estado = 'pendiente'): int
{
    return DB::table('invoices')->insertGetId([
        'tenant_id' => $t->id,
        'serie' => 'F001',
        'correlativo' => $correlativo,
        'fecha_emision' => $fechaEmision,
        'client_tipo_doc' => '6',
        'client_num_doc' => '20131312955',
        'client_razon_social' => 'CLIENTE SAC',
        'sunat_status' => $estado,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('auto-envía las que están por vencer y avisa; no toca las vencidas ni las lejanas', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    Queue::fake();
    $t = Tenant::factory()->create();

    // Plazo 3 días: límite = fecha_emision + 3.
    facturaEn($t, 1, Carbon::today()->subDays(2)->toDateString()); // límite = hoy+1 → por vencer
    facturaEn($t, 2, Carbon::today()->subDays(5)->toDateString()); // límite = hoy-2 → vencida
    facturaEn($t, 3, Carbon::today()->toDateString());             // límite = hoy+3 → lejana
    facturaEn($t, 4, Carbon::today()->subDays(2)->toDateString(), 'aceptado'); // final → ignorada

    $this->artisan('sunat:plazo-envio')->assertSuccessful();

    // Solo la #1 (por vencer) se auto-envía.
    Queue::assertPushed(SendDocumentToSunat::class, 1);
    // Se avisa al tenant (tiene por vencer + vencida).
    Queue::assertPushed(NotifyWebhookJob::class, 1);
});

test('con --sin-auto-envio solo avisa y no envía nada', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    Queue::fake();
    $t = Tenant::factory()->create();

    facturaEn($t, 1, Carbon::today()->subDays(2)->toDateString()); // por vencer

    $this->artisan('sunat:plazo-envio --sin-auto-envio')->assertSuccessful();

    Queue::assertNotPushed(SendDocumentToSunat::class);
    Queue::assertPushed(NotifyWebhookJob::class, 1);
});

test('sin comprobantes por vencer ni vencidos, no dispara nada', function () {
    config(['facturacion.plazo_envio_dias' => 3]);
    Queue::fake();
    $t = Tenant::factory()->create();

    facturaEn($t, 1, Carbon::today()->toDateString()); // lejana (hoy+3)

    $this->artisan('sunat:plazo-envio')->assertSuccessful();

    Queue::assertNotPushed(SendDocumentToSunat::class);
    Queue::assertNotPushed(NotifyWebhookJob::class);
});
