<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

function adminUser(): User
{
    return User::factory()->create([
        'role' => 'super_admin',
        'is_admin' => true,
        'is_active' => true,
    ]);
}

test('activar licencia desde el panel guarda credenciales (secreto cifrado), activa y audita', function () {
    Http::fake([
        '*/api/v1/licencias/activar' => Http::response(['valida' => true, 'codigo' => 'ok', 'mensaje' => 'Licencia activa'], 200),
    ]);

    $this->actingAs(adminUser())
        ->post('/admin/licencia/activar', [
            'license_key' => 'KEY-ABC-123',
            'license_secret' => 'SECRETO-XYZ',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = app(SettingsService::class);
    expect($settings->string(SettingsService::LICENSE_KEY))->toBe('KEY-ABC-123');
    // El secreto se guarda cifrado, pero descifra al valor original.
    expect(Crypt::decryptString($settings->string(SettingsService::LICENSE_SECRET)))->toBe('SECRETO-XYZ');

    // Se envió al servidor con la cabecera de la clave pegada.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/licencias/activar')
        && $r->header('X-License-Key')[0] === 'KEY-ABC-123');

    expect(AuditLog::where('accion', 'licencia.activada')->exists())->toBeTrue();
});

test('si el servidor rechaza, vuelve con error pero igual deja registro de auditoría', function () {
    Http::fake([
        '*/api/v1/licencias/activar' => Http::response(['valida' => false, 'codigo' => 'dominio_no_autorizado', 'mensaje' => 'No autorizado'], 200),
    ]);

    $this->actingAs(adminUser())
        ->post('/admin/licencia/activar', ['license_key' => 'K', 'license_secret' => 'S'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $log = AuditLog::where('accion', 'licencia.activada')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['ok'] ?? null)->toBeFalse();
});

test('la clave/secreto requeridos (422 si faltan)', function () {
    $this->actingAs(adminUser())
        ->postJson('/admin/licencia/activar', ['license_key' => '', 'license_secret' => ''])
        ->assertStatus(422);
});

test('las credenciales guardadas en BD pisan las de .env al arrancar', function () {
    config(['license.key' => 'de-env', 'license.secret' => 'secreto-env']);

    app(SettingsService::class)->setMany([
        SettingsService::LICENSE_KEY => 'de-bd',
        SettingsService::LICENSE_SECRET => Crypt::encryptString('secreto-bd'),
    ]);

    // Simula el arranque: AppServiceProvider::configureLicenseFromDb().
    $metodo = new ReflectionMethod(AppServiceProvider::class, 'configureLicenseFromDb');
    $metodo->setAccessible(true);
    $metodo->invoke(new AppServiceProvider(app()));

    expect(config('license.key'))->toBe('de-bd')
        ->and(config('license.secret'))->toBe('secreto-bd');
});
