<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Quién puede entrar a /horizon (además del middleware web+auth).
     *
     * Solo administradores activos: el dashboard permite reintentar y borrar
     * jobs, así que no se expone a roles de solo lectura ni a usuarios cliente.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null) {
            return $user !== null
                && $user->is_active
                && ($user->isSuperAdmin() || $user->is_admin);
        });
    }
}
