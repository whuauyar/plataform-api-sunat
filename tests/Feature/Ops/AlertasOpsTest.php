<?php

declare(strict_types=1);

use App\Support\Ops\OpsAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── OpsAlert ─────────────────────────────────────────────────────────────────

test('OpsAlert publica en el webhook cuando hay URL configurada', function () {
    config(['ops.webhook_url' => 'https://hooks.test/ops']);
    Http::fake();

    OpsAlert::critico('Prueba', 'mensaje', ['clave' => 'valor']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.test/ops'
            && $request['nivel'] === 'critical'
            && $request['titulo'] === 'Prueba'
            && str_contains($request['text'], 'Prueba')
            && str_contains($request['content'], 'Prueba'); // Slack y Discord
    });
});

test('OpsAlert no publica si no hay webhook (solo log)', function () {
    config(['ops.webhook_url' => null]);
    Http::fake();

    OpsAlert::aviso('Sin webhook', 'nada que enviar');

    Http::assertNothingSent();
});

test('OpsAlert no reenvía la misma clave dentro de la ventana de throttle', function () {
    config(['ops.webhook_url' => 'https://hooks.test/ops', 'ops.throttle_minutes' => 60]);
    Http::fake();

    OpsAlert::critico('Repetida', '', [], dedupeKey: 'misma-clave');
    OpsAlert::critico('Repetida', '', [], dedupeKey: 'misma-clave');

    Http::assertSentCount(1);
});

test('OpsAlert nunca lanza aunque el webhook falle', function () {
    config(['ops.webhook_url' => 'https://hooks.test/ops']);
    Http::fake(fn () => Http::response('boom', 500));

    OpsAlert::critico('Con webhook caído', 'debe seguir');

    expect(true)->toBeTrue(); // llegar aquí sin excepción es el test
});

// ── ops:jobs-fallidos ──────────────────────────────────────────────────────

test('ops:jobs-fallidos alerta cuando hay fallidos sobre el umbral', function () {
    config(['ops.webhook_url' => 'https://hooks.test/ops', 'ops.umbrales.jobs_fallidos' => 1]);
    Http::fake();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'database',
        'queue' => 'sunat',
        'payload' => '{}',
        'exception' => 'x',
        'failed_at' => now(),
    ]);

    $this->artisan('ops:jobs-fallidos --alertar')->assertExitCode(1);

    Http::assertSent(fn ($r) => $r['titulo'] === 'Jobs fallidos en cola');
});

test('ops:jobs-fallidos no alerta si no hay fallidos', function () {
    config(['ops.webhook_url' => 'https://hooks.test/ops']);
    Http::fake();

    $this->artisan('ops:jobs-fallidos --alertar')->assertExitCode(0);

    Http::assertNothingSent();
});

// ── ops:salud ────────────────────────────────────────────────────────────────

test('ops:salud corre y reporta con la BD arriba', function () {
    config(['ops.webhook_url' => null]);

    $this->artisan('ops:salud')->assertExitCode(0);
});
