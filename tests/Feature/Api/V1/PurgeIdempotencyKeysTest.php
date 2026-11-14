<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Inserta una llave de idempotencia con edad y estado dados. */
function llave(Tenant $t, string $key, string $createdAt, ?int $status): void
{
    DB::table('idempotency_keys')->insert([
        'tenant_id' => $t->id,
        'idempotency_key' => $key,
        'request_hash' => str_repeat('a', 64),
        'response_status' => $status,
        'response_body' => $status !== null ? '{"ok":true}' : null,
        'created_at' => $createdAt,
    ]);
}

test('purga llaves resueltas viejas y reservas en proceso colgadas, conservando las recientes', function () {
    $t = Tenant::factory()->create();

    llave($t, 'vieja-resuelta', now()->subDays(10)->toDateTimeString(), 201);      // se borra (> 7d)
    llave($t, 'reciente-resuelta', now()->subDay()->toDateTimeString(), 201);       // se conserva
    llave($t, 'colgada-vieja', now()->subHours(3)->toDateTimeString(), null);       // se borra (en proceso > 60m)
    llave($t, 'colgada-reciente', now()->subMinutes(5)->toDateTimeString(), null);  // se conserva

    $this->artisan('idempotency:purge')->assertSuccessful();

    $restantes = DB::table('idempotency_keys')->pluck('idempotency_key')->all();

    expect($restantes)->toContain('reciente-resuelta', 'colgada-reciente');
    expect($restantes)->not->toContain('vieja-resuelta');
    expect($restantes)->not->toContain('colgada-vieja');
});

test('respeta los parámetros --days y --stale-minutes', function () {
    $t = Tenant::factory()->create();

    llave($t, 'resuelta-2d', now()->subDays(2)->toDateTimeString(), 201);    // con --days=1 se borra
    llave($t, 'colgada-10m', now()->subMinutes(10)->toDateTimeString(), null); // con --stale-minutes=5 se borra

    $this->artisan('idempotency:purge --days=1 --stale-minutes=5')->assertSuccessful();

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});
