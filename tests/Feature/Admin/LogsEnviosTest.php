<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Feed global "Logs de envíos" (admin). Verifica que:
 *  - Por defecto es un feed reciente (ventana de N días), no recorre todo el histórico.
 *  - Una búsqueda explícita levanta la ventana y encuentra comprobantes antiguos.
 *  - Los filtros se aplican correctamente aunque vayan empujados dentro de cada rama.
 */
function adminActivo(): User
{
    $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
    test()->actingAs($admin);

    return $admin;
}

function factura(Tenant $tenant, int $correlativo, string $fechaEmision, string $estado = 'aceptado'): void
{
    DB::table('invoices')->insert([
        'tenant_id' => $tenant->id,
        'serie' => 'F001',
        'correlativo' => $correlativo,
        'fecha_emision' => $fechaEmision,
        'client_tipo_doc' => '6',
        'client_num_doc' => '20999999999',
        'client_razon_social' => 'CLIENTE SAC',
        'sunat_status' => $estado,
        'mto_imp_venta' => 118.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('el feed por defecto solo muestra comprobantes dentro de la ventana reciente', function () {
    config(['reportes.logs_envios_dias' => 30]);
    adminActivo();
    $tenant = Tenant::factory()->create(['razon_social' => 'ACME CORP SAC']);

    factura($tenant, 1, now()->format('Y-m-d'));            // reciente
    factura($tenant, 2, now()->subDays(200)->format('Y-m-d')); // antiguo (fuera de ventana)

    $this->get('/admin/logs-envios')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('admin/logs-envios')
            ->has('logs.data', 1)
            ->where('logs.data.0.correlativo', 1)
    );
});

test('una búsqueda explícita levanta la ventana y encuentra comprobantes antiguos', function () {
    config(['reportes.logs_envios_dias' => 30]);
    adminActivo();
    $tenant = Tenant::factory()->create(['razon_social' => 'ACME CORP SAC']);

    factura($tenant, 1, now()->format('Y-m-d'));
    factura($tenant, 2, now()->subDays(200)->format('Y-m-d'));

    $this->get('/admin/logs-envios?buscar=ACME')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('admin/logs-envios')
            ->has('logs.data', 2)
    );
});

test('el filtro de estado acota por estado dentro de la ventana', function () {
    config(['reportes.logs_envios_dias' => 30]);
    adminActivo();
    $tenant = Tenant::factory()->create(['razon_social' => 'ACME CORP SAC']);

    factura($tenant, 1, now()->format('Y-m-d'), 'aceptado');
    factura($tenant, 2, now()->format('Y-m-d'), 'rechazado');

    $this->get('/admin/logs-envios?estado=rechazado')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('admin/logs-envios')
            ->has('logs.data', 1)
            ->where('logs.data.0.estado', 'rechazado')
    );
});

test('ventana 0 restaura el histórico completo (compatibilidad hacia atrás)', function () {
    config(['reportes.logs_envios_dias' => 0]);
    adminActivo();
    $tenant = Tenant::factory()->create(['razon_social' => 'ACME CORP SAC']);

    factura($tenant, 1, now()->format('Y-m-d'));
    factura($tenant, 2, now()->subDays(200)->format('Y-m-d'));

    $this->get('/admin/logs-envios')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('admin/logs-envios')
            ->has('logs.data', 2)
    );
});
