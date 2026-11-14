<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('usuario:reset-password restablece la contraseña de un usuario existente', function () {
    $user = User::factory()->create(['email' => 'admin@demo.com', 'password' => Hash::make('vieja-clave')]);

    $this->artisan('usuario:reset-password', ['email' => 'admin@demo.com', '--password' => 'NuevaClave123'])
        ->assertExitCode(0);

    expect(Hash::check('NuevaClave123', $user->fresh()->password))->toBeTrue();
});

test('usuario:reset-password falla si el email no existe', function () {
    $this->artisan('usuario:reset-password', ['email' => 'noexiste@demo.com'])->assertExitCode(1);
});
