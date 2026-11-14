<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Anti-escalada de privilegios en el panel de usuarios: un admin NO puede crear,
 * ascender a, ni modificar/desactivar a un super_admin. Solo un super_admin puede.
 */
function actorConRol(string $rol): User
{
    $u = User::factory()->create(['role' => $rol, 'is_active' => true, 'is_admin' => true]);
    test()->actingAs($u);

    return $u;
}

const PASS_VALIDA = 'Claro#2026';

test('un admin NO puede crear un super_admin', function () {
    actorConRol(User::ROLE_ADMIN);

    $this->post('/admin/usuarios', [
        'name' => 'Intruso',
        'email' => 'intruso@demo.com',
        'password' => PASS_VALIDA,
        'role' => User::ROLE_SUPER_ADMIN,
    ])->assertSessionHasErrors(['role']);

    expect(User::where('email', 'intruso@demo.com')->exists())->toBeFalse();
});

test('un admin NO puede ascender a un usuario existente a super_admin', function () {
    actorConRol(User::ROLE_ADMIN);
    $objetivo = User::factory()->create(['role' => User::ROLE_LECTURA, 'is_active' => true]);

    $this->put("/admin/usuarios/{$objetivo->id}", [
        'name' => $objetivo->name,
        'email' => $objetivo->email,
        'role' => User::ROLE_SUPER_ADMIN,
    ])->assertSessionHasErrors(['role']);

    expect($objetivo->fresh()->role)->toBe(User::ROLE_LECTURA);
});

test('un admin NO puede editar a un super_admin existente', function () {
    actorConRol(User::ROLE_ADMIN);
    $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);

    $this->put("/admin/usuarios/{$super->id}", [
        'name' => 'Hackeado',
        'email' => $super->email,
        'role' => User::ROLE_ADMIN,
    ])->assertForbidden();

    expect($super->fresh()->role)->toBe(User::ROLE_SUPER_ADMIN);
});

test('un admin NO puede desactivar a un super_admin', function () {
    actorConRol(User::ROLE_ADMIN);
    $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);

    $this->post("/admin/usuarios/{$super->id}/toggle")->assertForbidden();

    expect($super->fresh()->is_active)->toBeTrue();
});

test('un super_admin SÍ puede crear otro super_admin', function () {
    actorConRol(User::ROLE_SUPER_ADMIN);

    $this->post('/admin/usuarios', [
        'name' => 'Nuevo Super',
        'email' => 'super2@demo.com',
        'password' => PASS_VALIDA,
        'role' => User::ROLE_SUPER_ADMIN,
    ])->assertRedirect();

    expect(User::where('email', 'super2@demo.com')->value('role'))->toBe(User::ROLE_SUPER_ADMIN);
});

test('un admin SÍ puede crear un rol no privilegiado (sin regresión)', function () {
    actorConRol(User::ROLE_ADMIN);

    $this->post('/admin/usuarios', [
        'name' => 'Soporte Uno',
        'email' => 'soporte@demo.com',
        'password' => PASS_VALIDA,
        'role' => User::ROLE_SOPORTE,
    ])->assertRedirect();

    expect(User::where('email', 'soporte@demo.com')->value('role'))->toBe(User::ROLE_SOPORTE);
});
