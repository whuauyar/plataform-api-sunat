<?php

use App\Models\User;

test('la raíz redirige al registro cuando no hay usuarios (primer arranque)', function () {
    $this->get(route('home'))->assertRedirectContains('/register');
});

test('la raíz redirige al login cuando ya existe algún usuario', function () {
    User::factory()->create();

    $this->get(route('home'))->assertRedirectContains('/login');
});
