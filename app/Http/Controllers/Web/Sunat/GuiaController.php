<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Actions\Documents\CreateDispatchGuideAction;
use App\Actions\Documents\UpdateDispatchGuideAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDispatchGuideRequest;
use App\Models\Client;
use App\Models\DispatchGuide;
use App\Models\Serie;
use App\Services\Tenancy\EmpresaActiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guía de remisión electrónica desde el panel:
 *   - Remitente (09): la empresa traslada sus bienes.
 *   - Transportista (31): la empresa transportista traslada bienes de un tercero.
 * Reutiliza CreateDispatchGuideAction (la misma de la API).
 */
class GuiaController extends Controller
{
    public function index(\Illuminate\Http\Request $request, \App\Services\Documents\DocumentoListingService $listing): Response|RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actual();
        if (! $tenant) {
            return redirect()->route('sunat.configuracion');
        }

        $filtros = ['buscar' => $request->input('buscar')];

        return Inertia::render('sunat/documentos/index', [
            'titulo' => 'Guías de remisión',
            'subtitulo' => 'Guías de remisión emitidas',
            'nuevo' => ['href' => '/sunat/guias/nueva', 'label' => 'Nueva guía'],
            'documentos' => $listing->paginar($tenant, 'guias', $filtros, 25),
            'filtros' => $filtros,
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actual();

        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion')
                ->with('warning', 'Configura tus credenciales SUNAT primero.');
        }

        $series = fn (string $tipo) => Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', $tipo)
            ->where('is_active', true)
            ->get(['id', 'serie', 'tipo_documento', 'correlativo']);

        $clientes = Client::where('tenant_id', $tenant->id)
            ->orderBy('razon_social')
            ->limit(50)
            ->get(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'email']);

        return Inertia::render('sunat/guias/nueva', [
            'tenant' => [
                'ruc' => $tenant->ruc,
                'razon_social' => $tenant->razon_social,
                'environment' => $tenant->environment ?? 'beta',
            ],
            'series_remitente' => $series('09'),
            'series_transportista' => $series('31'),
            'clientes' => $clientes,
        ]);
    }

    public function store(Request $request, CreateDispatchGuideAction $action): RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actualOFallar();

        $tipo = $request->input('tipo_documento', '09');
        $ability = $tipo === '31' ? 'guia_transportista' : 'guia_remitente';
        Gate::authorize('emitir', [$tenant, $ability]);

        $enviar = $request->boolean('enviar_automatico', true);

        try {
            $guide = $action->execute($tenant, $request->all(), $enviar);
            $numero = $guide->serie.'-'.str_pad((string) $guide->correlativo, 8, '0', STR_PAD_LEFT);

            $msg = $enviar
                ? "Guía de remisión {$numero} emitida y enviada a SUNAT."
                : "Guía de remisión {$numero} guardada como borrador.";

            return redirect()->route('sunat.guias.index')
                ->with('success', $msg)
                ->with('emitido', ['tipo' => $tipo, 'id' => $guide->id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Error al emitir guía: '.$e->getMessage());
        }
    }

    /** Edita una guía RECHAZADA/PENDIENTE (precarga el formulario). */
    public function edit(int $id): Response|RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actual();
        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion');
        }

        $g = DispatchGuide::forTenant($tenant->id)->findOrFail($id);
        if (! in_array($g->sunat_status, ['pendiente', 'rechazado'], true)) {
            return redirect()->route('sunat.guias.index')->with('error', 'Solo se pueden editar guías pendientes o rechazadas.');
        }

        $series = fn (string $tipo) => Serie::where('tenant_id', $tenant->id)->where('tipo_documento', $tipo)->where('is_active', true)->get(['id', 'serie', 'tipo_documento', 'correlativo']);

        $editar = [
            'id' => $g->id,
            'tipo' => $g->tipo_documento,
            'serie' => $g->serie,
            'correlativo' => $g->correlativo,
            'fecha_emision' => optional($g->fecha_emision)->format('Y-m-d') ?? (string) $g->fecha_emision,
            'fecha_traslado' => optional($g->fecha_traslado)->format('Y-m-d') ?? (string) $g->fecha_traslado,
            'observacion' => $g->observacion ?? '',
            'cod_traslado' => $g->cod_traslado ?? '01',
            'mod_traslado' => $g->mod_traslado ?? '02',
            'peso' => (string) ($g->peso_total ?? '1'),
            'und_peso' => $g->und_peso_total ?? 'KGM',
            'num_bultos' => $g->num_bultos ? (string) $g->num_bultos : '',
            'partida_ubigeo' => $g->partida_ubigeo ?? '',
            'partida_direccion' => $g->partida_direccion ?? '',
            'llegada_ubigeo' => $g->llegada_ubigeo ?? '',
            'llegada_direccion' => $g->llegada_direccion ?? '',
            'destinatario' => ['tipo_doc' => $g->destinatario_tipo_doc, 'num_doc' => $g->destinatario_num_doc, 'razon_social' => $g->destinatario_razon_social],
            'remitente' => $g->remitente ? ['tipo_doc' => $g->remitente['tipo_doc'] ?? '6', 'num_doc' => $g->remitente['num_doc'] ?? '', 'razon_social' => $g->remitente['razon_social'] ?? ''] : null,
            'transportista' => $g->transportista ? ['num_doc' => $g->transportista['num_doc'] ?? '', 'razon_social' => $g->transportista['razon_social'] ?? '', 'nro_mtc' => $g->transportista['nro_mtc'] ?? ''] : null,
            'vehiculo' => $g->vehiculo ? [
                'placa' => $g->vehiculo['placa'] ?? '',
                'secundarios' => array_values(array_map(fn ($s) => is_array($s) ? ($s['placa'] ?? '') : $s, $g->vehiculo['secundarios'] ?? [])),
            ] : null,
            'conductores' => array_values(array_map(fn ($c) => [
                'tipo_doc' => $c['tipo_doc'] ?? '1',
                'num_doc' => $c['num_doc'] ?? '',
                'nombres' => $c['nombres'] ?? '',
                'apellidos' => $c['apellidos'] ?? '',
                'licencia' => $c['licencia'] ?? '',
            ], $g->conductor ?? [])),
            'doc_relacionado' => ($g->doc_relacionado[0] ?? null)
                ? ['tipo_codigo' => $g->doc_relacionado[0]['tipo_codigo'] ?? '01', 'numero' => $g->doc_relacionado[0]['numero'] ?? '', 'ruc_emisor' => $g->doc_relacionado[0]['ruc_emisor'] ?? '']
                : null,
            'subcontratador' => $g->datos_subcontratador ? ['num_doc' => $g->datos_subcontratador['num_doc'] ?? '', 'razon_social' => $g->datos_subcontratador['razon_social'] ?? ''] : null,
            'pagador_flete' => $g->datos_pagador_flete ? ['tipo' => $g->datos_pagador_flete['tipo'] ?? '', 'num_doc' => $g->datos_pagador_flete['num_doc'] ?? '', 'razon_social' => $g->datos_pagador_flete['razon_social'] ?? ''] : null,
            'items' => array_values(array_map(fn ($it) => ['descripcion' => $it['descripcion'] ?? '', 'cantidad' => (string) ($it['cantidad'] ?? '1'), 'unidad' => $it['unidad'] ?? 'NIU'], $g->items ?? [])),
        ];

        return Inertia::render('sunat/guias/nueva', [
            'tenant' => ['ruc' => $tenant->ruc, 'razon_social' => $tenant->razon_social, 'environment' => $tenant->environment ?? 'beta'],
            'series_remitente' => $series('09'),
            'series_transportista' => $series('31'),
            'clientes' => [],
            'editar' => $editar,
            'post_url' => "/sunat/guias/{$id}/actualizar",
        ]);
    }

    /** Actualiza y reenvía una guía rechazada/pendiente (misma lógica que la API). */
    public function update(Request $request, int $id, UpdateDispatchGuideAction $action): RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actualOFallar();
        $guide = DispatchGuide::forTenant($tenant->id)->findOrFail($id);

        if (! in_array($guide->sunat_status, ['pendiente', 'rechazado'], true)) {
            return back()->with('error', 'Solo se pueden editar guías pendientes o rechazadas.');
        }

        $tipo = $request->input('tipo_documento', $guide->tipo_documento);
        Gate::authorize('emitir', [$tenant, $tipo === '31' ? 'guia_transportista' : 'guia_remitente']);

        // Validar con el mismo FormRequest de la API (reglas GRR/GRT).
        $formRequest = StoreDispatchGuideRequest::create('', 'POST', $request->all());
        $formRequest->setContainer(app())->setRedirector(app('redirect'));
        $formRequest->attributes->set('tenant', $tenant);
        try {
            $formRequest->validateResolved();
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->with('error', 'Revisa los datos de la guía.');
        }

        try {
            $guide = $action->execute($guide, $formRequest->validated());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Error al actualizar la guía: '.$e->getMessage());
        }

        $numero = $guide->serie.'-'.str_pad((string) $guide->correlativo, 8, '0', STR_PAD_LEFT);

        return redirect()->route('sunat.guias.index')
            ->with('success', "Guía {$numero} actualizada y reenviada a SUNAT.")
            ->with('emitido', ['tipo' => $guide->tipo_documento, 'id' => $guide->id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
    }
}
