<?php

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        // Debe cumplir PasswordPolicy (mayúscula, minúscula, número y símbolo).
        'password' => 'Clave#2026',
        'password_confirmation' => 'Clave#2026',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
