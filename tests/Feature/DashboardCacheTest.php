<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

function adminInterno(): User
{
    return User::factory()->create([
        'role' => User::ROLE_SUPER_ADMIN,
        'is_admin' => true,
        'is_active' => true,
    ]);
}

test('el dashboard admin renderiza métricas y las cachea', function () {
    config(['reportes.dashboard_cache_segundos' => 300]);
    Cache::forget('dashboard:metricas:global');

    $this->actingAs(adminInterno())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('esAdmin', true)
            ->has('metricas.kpis')
        );

    expect(Cache::has('dashboard:metricas:global'))->toBeTrue();
});

test('con ttl=0 el dashboard no cachea (recalcula siempre)', function () {
    config(['reportes.dashboard_cache_segundos' => 0]);
    Cache::forget('dashboard:metricas:global');

    $this->actingAs(adminInterno())
        ->get(route('dashboard'))
        ->assertOk();

    expect(Cache::has('dashboard:metricas:global'))->toBeFalse();
});
