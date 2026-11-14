<?php

declare(strict_types=1);

use App\Jobs\NotifyWebhookJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

test('el webhook en modo tenant+payload se envía firmado (SIRE reconcile)', function () {
    Http::fake();
    $tenant = Tenant::factory()->create([
        'webhook_url' => 'https://cliente.test/hook',
        'api_secret' => 'sec-sire',
    ]);

    NotifyWebhookJob::dispatch(
        tenantId: $tenant->id,
        event: 'reconciliation.completed',
        payload: ['periodo' => '202609', 'coincidencias' => 10],
    )->handle();

    Http::assertSent(function ($request) use ($tenant) {
        $sig = $request->header('X-Signature')[0] ?? '';
        $esperado = 'sha256='.hash_hmac('sha256', $request->body(), $tenant->api_secret);
        $cuerpo = json_decode($request->body(), true);

        return $request->url() === 'https://cliente.test/hook'
            && $request->header('X-Webhook-Event')[0] === 'reconciliation.completed'
            && $cuerpo['event'] === 'reconciliation.completed'
            && $cuerpo['document']['periodo'] === '202609'
            && hash_equals($esperado, $sig);
    });
});

test('el webhook en modo tenant no envía si el tenant no tiene webhook_url', function () {
    Http::fake();
    $tenant = Tenant::factory()->create(['webhook_url' => null]);

    NotifyWebhookJob::dispatch(tenantId: $tenant->id, event: 'reconciliation.completed', payload: ['x' => 1])->handle();

    Http::assertNothingSent();
});
