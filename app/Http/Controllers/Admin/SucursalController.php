<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('admin/sucursales/index', [
            'tenant' => $this->tenantMini($tenant),
            'sucursales' => $tenant->sucursales()
                ->orderByDesc('is_principal')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Sucursal $s) => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'cod_local' => $s->cod_local,
                    'direccion' => $s->direccion,
                    'ubigeo' => $s->ubigeo,
                    'telefono' => $s->telefono,
                    'email' => $s->email,
                    'is_principal' => (bool) $s->is_principal,
                    'is_active' => (bool) $s->is_active,
                ])->all(),
        ]);
    }

    public function create(Request $request, Tenant $tenant): Response
    {
        return Inertia::render('admin/sucursales/form', [
            'tenant' => $this->tenantMini($tenant),
            'sucursal' => [
                'id' => null,
                'nombre' => '',
                'cod_local' => '0000',
                'direccion' => '',
                'ubigeo' => '',
                'telefono' => '',
                'email' => '',
                'is_principal' => false,
                'is_active' => true,
            ],
            'modo' => 'crear',
            // Alta guiada tras registrar la empresa: al guardar, continúa a crear serie.
            'onboarding' => $request->boolean('onboarding'),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validar($request, $tenant);

        // Solo puede haber una principal
        if (! empty($data['is_principal'])) {
            $tenant->sucursales()->update(['is_principal' => false]);
        }

        $tenant->sucursales()->create($data);

        // Flujo guiado (empresa → sucursal → serie): sigue directo a crear la serie.
        if ($request->boolean('onboarding')) {
            return redirect()
                ->route('admin.series.create', ['tenant' => $tenant, 'onboarding' => 1])
                ->with('success', 'Sucursal creada. Ahora crea tu primera serie.');
        }

        return redirect()->route('admin.sucursales.index', $tenant)
            ->with('success', 'Sucursal creada.');
    }

    public function edit(Tenant $tenant, Sucursal $sucursal): Response
    {
        abort_unless($sucursal->tenant_id === $tenant->id, 404);

        return Inertia::render('admin/sucursales/form', [
            'tenant' => $this->tenantMini($tenant),
            'sucursal' => [
                'id' => $sucursal->id,
                'nombre' => $sucursal->nombre,
                'cod_local' => $sucursal->cod_local,
                'direccion' => $sucursal->direccion ?? '',
                'ubigeo' => $sucursal->ubigeo ?? '',
                'telefono' => $sucursal->telefono ?? '',
                'email' => $sucursal->email ?? '',
                'is_principal' => (bool) $sucursal->is_principal,
                'is_active' => (bool) $sucursal->is_active,
            ],
            'modo' => 'editar',
        ]);
    }

    private function tenantMini(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'ruc' => $tenant->ruc,
            'razon_social' => $tenant->razon_social,
        ];
    }

    public function update(Request $request, Tenant $tenant, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->tenant_id === $tenant->id, 404);

        $data = $this->validar($request, $tenant, $sucursal->id);

        if (! empty($data['is_principal'])) {
            $tenant->sucursales()->where('id', '!=', $sucursal->id)->update(['is_principal' => false]);
        }

        $sucursal->update($data);

        return redirect()->route('admin.sucursales.index', $tenant)
            ->with('success', 'Sucursal actualizada.');
    }

    public function destroy(Tenant $tenant, Sucursal $sucursal): RedirectResponse
    {
        abort_unless($sucursal->tenant_id === $tenant->id, 404);
        $sucursal->delete();

        return redirect()->route('admin.sucursales.index', $tenant)
            ->with('success', 'Sucursal eliminada.');
    }

    private function validar(Request $request, Tenant $tenant, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'cod_local' => 'required|string|size:4',
            'direccion' => 'required|string|max:500',
            'ubigeo' => 'required|string|between:4,6',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'is_principal' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ], [
            'nombre.required' => 'El nombre de la sucursal es obligatorio.',
            'cod_local.required' => 'El código de local es obligatorio.',
            'cod_local.size' => 'El código de local debe tener 4 dígitos (ej: 0000).',
            'direccion.required' => 'La dirección es obligatoria.',
            'ubigeo.required' => 'El ubigeo es obligatorio.',
            'ubigeo.between' => 'El ubigeo debe tener entre 4 y 6 dígitos (ej: 150101).',
            'email.email' => 'El correo no tiene un formato válido.',
        ]);

        $data['is_principal'] = $request->boolean('is_principal');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
