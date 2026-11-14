<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bitácora de acciones sensibles (S5): visor del registro `audit_logs` en el
 * panel admin. Paginado en servidor, con búsqueda por acción/detalle/actor.
 */
class BitacoraController extends Controller
{
    public function index(Request $request): Response
    {
        $buscar = trim((string) $request->input('buscar', ''));

        $logs = AuditLog::query()
            ->with(['tenant:id,razon_social,ruc'])
            ->when($buscar !== '', function ($q) use ($buscar) {
                $q->where(fn ($qq) => $qq
                    ->where('accion', 'like', "%{$buscar}%")
                    ->orWhere('descripcion', 'like', "%{$buscar}%")
                    ->orWhere('actor_etiqueta', 'like', "%{$buscar}%"));
            })
            ->when($request->filled('accion'), fn ($q) => $q->where('accion', $request->input('accion')))
            ->when($request->filled('actor_tipo'), fn ($q) => $q->where('actor_tipo', $request->input('actor_tipo')))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/bitacora/index', [
            'logs' => $logs->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'accion' => $l->accion,
                'actor_tipo' => $l->actor_tipo,
                'actor' => $l->actor_etiqueta ?: $l->actor_tipo,
                'descripcion' => $l->descripcion,
                'empresa' => $l->tenant?->razon_social,
                'tenant_id' => $l->tenant_id,
                'ip' => $l->ip,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'filtros' => [
                'buscar' => $buscar,
                'accion' => $request->input('accion', ''),
                'actor_tipo' => $request->input('actor_tipo', ''),
            ],
        ]);
    }
}
