<?php

declare(strict_types=1);

use App\Jobs\NotifyWebhookJob;
use App\Models\Boleta;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function headersSeg(Tenant $t, array $extra = []): array
{
    return array_merge([
        'X-Api-Key' => $t->api_key,
        'X-Api-Secret' => $t->api_secret,
        'Accept' => 'application/json',
    ], $extra);
}

// ── Idempotencia ─────────────────────────────────────────────────────────────

test('misma Idempotency-Key no crea duplicado y reproduce la respuesta', function () {
    $tenant = Tenant::factory()->create();
    $payload = ['tipo_doc' => '6', 'num_doc' => '20512345678', 'razon_social' => 'CLIENTE IDEM SAC'];
    $headers = headersSeg($tenant, ['Idempotency-Key' => 'abc-123']);

    $r1 = $this->postJson('/api/v1/clientes', $payload, $headers);
    $r1->assertCreated();
    expect($r1->headers->get('Idempotency-Replayed'))->toBe('false');

    $r2 = $this->postJson('/api/v1/clientes', $payload, $headers);
    $r2->assertCreated();
    expect($r2->headers->get('Idempotency-Replayed'))->toBe('true');

    // Clave: NO se creó un segundo cliente.
    expect(Client::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('misma Idempotency-Key con cuerpo distinto responde 422', function () {
    $tenant = Tenant::factory()->create();
    $h = headersSeg($tenant, ['Idempotency-Key' => 'k1']);

    $this->postJson('/api/v1/clientes', ['tipo_doc' => '6', 'num_doc' => '20512345678', 'razon_social' => 'A'], $h)
        ->assertCreated();
    $this->postJson('/api/v1/clientes', ['tipo_doc' => '6', 'num_doc' => '20512345679', 'razon_social' => 'B'], $h)
        ->assertStatus(422);
});

// ── api_secret cifrado en reposo ─────────────────────────────────────────────

test('api_secret se guarda cifrado en la BD pero se lee en claro', function () {
    $tenant = Tenant::factory()->create(['api_secret' => 'secreto-plano-123']);

    $raw = DB::table('tenants')->where('id', $tenant->id)->value('api_secret');
    expect($raw)->not->toBe('secreto-plano-123');                 // cifrado en reposo
    expect($tenant->fresh()->api_secret)->toBe('secreto-plano-123'); // descifra al leer
});

test('la autenticación sigue funcionando con api_secret cifrado', function () {
    $tenant = Tenant::factory()->create();
    $this->getJson('/api/v1/clientes', headersSeg($tenant))->assertOk();
    $this->getJson('/api/v1/clientes', headersSeg($tenant, ['X-Api-Secret' => 'mal']))->assertStatus(401);
});

// ── Headers de seguridad + auth solo por cabecera ────────────────────────────

test('las respuestas llevan cabeceras de seguridad', function () {
    $res = $this->getJson('/api/v1/clientes', headersSeg(Tenant::factory()->create()));

    $res->assertOk();
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($res->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

test('la autenticación por query string ya no funciona (solo cabecera)', function () {
    $tenant = Tenant::factory()->create();

    // Credenciales correctas pero enviadas por query string → 401.
    $this->getJson('/api/v1/clientes?api_key='.$tenant->api_key.'&api_secret='.$tenant->api_secret)
        ->assertStatus(401);
});

// ── Webhook firmado (HMAC) ───────────────────────────────────────────────────

test('el webhook se envía firmado con HMAC-SHA256 del api_secret', function () {
    Http::fake();
    $tenant = Tenant::factory()->create([
        'webhook_url' => 'https://cliente.test/hook',
        'api_secret' => 'sec-xyz',
    ]);

    $b = new Boleta();
    $b->forceFill([
        'tenant_id' => $tenant->id, 'serie' => 'B001', 'correlativo' => 1,
        'fecha_emision' => now()->toDateString(), 'client_tipo_doc' => '1',
        'client_num_doc' => '12345678', 'client_razon_social' => 'CLIENTE',
        'sunat_status' => 'aceptado',
    ]);
    $b->save();

    (new NotifyWebhookJob(Boleta::class, $b->id, 'document.sent'))->handle();

    Http::assertSent(function ($request) use ($tenant) {
        $sig = $request->header('X-Signature')[0] ?? '';
        $esperado = 'sha256='.hash_hmac('sha256', $request->body(), $tenant->api_secret);

        return $request->url() === 'https://cliente.test/hook'
            && $sig !== '' && hash_equals($esperado, $sig);
    });
});
