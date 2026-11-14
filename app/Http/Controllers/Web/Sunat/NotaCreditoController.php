<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Actions\Documents\CreateCreditNoteAction;
use App\Http\Controllers\Api\V1\CreditNoteController as ApiCreditNoteController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Serie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotaCreditoController extends Controller
{
    public function index(\App\Services\Documents\DocumentoListingService $listing): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();
        if (! $tenant) {
            return redirect()->route('sunat.configuracion');
        }

        return Inertia::render('sunat/documentos/index', [
            'titulo' => 'Notas de crédito',
            'subtitulo' => 'Notas de crédito emitidas',
            'nuevo' => ['href' => '/sunat/nota-credito/nueva', 'label' => 'Nueva nota de crédito'],
            'documentos' => $listing->listar($tenant, 'notas-credito'),
        ]);
    }

    private const MOTIVOS = [
        ['codigo' => '01', 'descripcion' => 'Anulación de la operación'],
        ['codigo' => '02', 'descripcion' => 'Anulación por error en el RUC'],
        ['codigo' => '03', 'descripcion' => 'Corrección por error en la descripción'],
        ['codigo' => '04', 'descripcion' => 'Descuento global'],
        ['codigo' => '05', 'descripcion' => 'Descuento por ítem'],
        ['codigo' => '06', 'descripcion' => 'Devolución total'],
        ['codigo' => '07', 'descripcion' => 'Devolución por ítem'],
        ['codigo' => '08', 'descripcion' => 'Bonificación'],
        ['codigo' => '09', 'descripcion' => 'Disminución en el valor'],
        ['codigo' => '10', 'descripcion' => 'Otros conceptos'],
        ['codigo' => '11', 'descripcion' => 'Ajustes de operaciones de exportación'],
        ['codigo' => '13', 'descripcion' => 'Corrección en el monto de percepción'],
    ];

    public function create(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion');
        }

        $docOriginal = null;
        if ($docId = $request->input('doc_id')) {
            $tipoAfectado = $request->input('tipo_doc', '01');
            $model = $tipoAfectado === '03' ? Boleta::class : Invoice::class;
            $found = $model::forTenant($tenant->id)->with('items')->find($docId);

            if ($found) {
                $docOriginal = [
                    'id' => $found->id,
                    'tipo_doc' => $tipoAfectado,
                    'serie' => $found->serie,
                    'correlativo' => $found->correlativo,
                    'numero' => $found->serie.'-'.str_pad((string) $found->correlativo, 8, '0', STR_PAD_LEFT),
                    'cliente' => $found->client_razon_social,
                    'cliente_tipo_doc' => $found->client_tipo_doc,
                    'cliente_num_doc' => $found->client_num_doc,
                    'cliente_direccion' => $found->client_direccion,
                    'total' => (float) $found->mto_imp_venta,
                    'moneda' => $found->tipo_moneda ?? 'PEN',
                    'items' => $found->items->map(fn ($i) => [
                        'codigo' => $i->codigo ?? '',
                        'cod_producto_sunat' => $i->cod_producto_sunat ?? '',
                        'descripcion' => $i->descripcion,
                        'cantidad' => (float) $i->cantidad,
                        // El editor trabaja con el precio unitario CON IGV; se prioriza
                        // mto_precio_unitario y se cae a mto_valor_unitario si faltara.
                        'precio_unitario' => (float) ($i->mto_precio_unitario ?? $i->mto_valor_unitario ?? 0),
                        'unidad' => $i->unidad ?? 'NIU',
                        'tip_afe_igv' => $i->tip_afe_igv ?? '10',
                    ])->toArray(),
                ];
            }
        }

        $series = Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', '07')
            ->where('is_active', true)
            ->get(['id', 'serie', 'tipo_documento', 'correlativo']);

        return Inertia::render('sunat/nota-credito/nueva', [
            'motivos' => self::MOTIVOS,
            'series' => $series,
            'doc_original' => $docOriginal,
            'tenant' => ['ruc' => $tenant->ruc, 'razon_social' => $tenant->razon_social],
        ]);
    }

    public function store(Request $request, CreateCreditNoteAction $action): \Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();
        \Illuminate\Support\Facades\Gate::authorize('emitir', [$tenant, 'nota_credito']);

        $enviar = $request->boolean('enviar_automatico', true);

        $data = $request->all();
        // Compatibilidad: el formulario antiguo enviaba 'doc_afectado_corr' (abreviado);
        // CreateCreditNoteAction espera 'doc_afectado_correlativo'. Sin esto, el
        // correlativo del documento afectado llegaba vacío (bug reportado).
        if (! isset($data['doc_afectado_correlativo']) && isset($data['doc_afectado_corr'])) {
            $data['doc_afectado_correlativo'] = $data['doc_afectado_corr'];
        }

        try {
            $nc = $action->execute($tenant, $data, $enviar);
            $numero = $nc->serie.'-'.str_pad((string) $nc->correlativo, 8, '0', STR_PAD_LEFT);

            $msg = $enviar
                ? "Nota de Crédito {$numero} emitida y enviada a SUNAT."
                : "Nota de Crédito {$numero} guardada como borrador.";

            return redirect()->route('sunat.nota-credito.index')
                ->with('success', $msg)
                ->with('emitido', ['tipo' => '07', 'id' => $nc->id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Error: '.$e->getMessage());
        }
    }

    /** Edita una nota de crédito RECHAZADA/PENDIENTE (precarga el form). */
    public function edit(int $id): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();
        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion');
        }

        $nc = CreditNote::forTenant($tenant->id)->with('items')->findOrFail($id);
        if (in_array($nc->sunat_status, ['aceptado', 'anulado', 'anulacion_en_proceso'], true)) {
            return redirect()->route('sunat.nota-credito.index')->with('error', 'Solo se pueden editar notas rechazadas o pendientes.');
        }

        $editar = [
            'id' => $nc->id,
            'serie' => $nc->serie,
            'correlativo' => $nc->correlativo,
            'fecha' => optional($nc->fecha_emision)->format('Y-m-d') ?? (string) $nc->fecha_emision,
            'moneda' => $nc->tipo_moneda ?? 'PEN',
            'cod_motivo' => $nc->cod_motivo,
            'des_motivo' => $nc->des_motivo,
            'cliente' => [
                'tipo_doc' => $nc->client_tipo_doc,
                'num_doc' => $nc->client_num_doc,
                'razon_social' => $nc->client_razon_social,
                'direccion' => $nc->client_direccion ?? '',
            ],
            'doc_afectado' => [
                'tipo' => $nc->doc_afectado_tipo,
                'serie' => $nc->doc_afectado_serie,
                'correlativo' => (int) $nc->doc_afectado_correlativo,
                'numero' => $nc->doc_afectado_serie.'-'.str_pad((string) $nc->doc_afectado_correlativo, 8, '0', STR_PAD_LEFT),
            ],
            'items' => $nc->items->map(fn ($i) => [
                'codigo' => $i->codigo ?? '',
                'cod_producto_sunat' => $i->cod_producto_sunat ?? '',
                'descripcion' => $i->descripcion,
                'cantidad' => (float) $i->cantidad,
                'precio_unitario' => (float) ($i->mto_precio_unitario ?? $i->mto_valor_unitario ?? 0),
                'unidad' => $i->unidad ?? 'NIU',
                'tip_afe_igv' => $i->tip_afe_igv ?? '10',
            ])->values(),
        ];

        return Inertia::render('sunat/nota-credito/nueva', [
            'motivos' => self::MOTIVOS,
            'series' => Serie::where('tenant_id', $tenant->id)->where('tipo_documento', '07')->where('is_active', true)->get(['id', 'serie', 'tipo_documento', 'correlativo']),
            'doc_original' => null,
            'tenant' => ['ruc' => $tenant->ruc, 'razon_social' => $tenant->razon_social],
            'editar' => $editar,
            'post_url' => "/sunat/nota-credito/{$id}/actualizar",
        ]);
    }

    /** Actualiza y reenvía una nota rechazada (delega en el update de la API). */
    public function update(Request $request, int $id): \Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();
        Gate::authorize('emitir', [$tenant, 'nota_credito']);

        $apiRequest = Request::create('', 'PUT', $request->all());
        $apiRequest->merge(['tenant' => $tenant]);
        $apiRequest->headers->set('Accept', 'application/json');

        $body = app(ApiCreditNoteController::class)->update($apiRequest, $id)->getData(true);

        if (($body['estado'] ?? null) !== 'exito') {
            return back()->withInput()->with('error', $body['mensaje'] ?? 'No se pudo actualizar la nota de crédito.');
        }

        $numero = $body['datos']['numero_completo'] ?? '';

        return redirect()->route('sunat.nota-credito.index')
            ->with('success', "Nota de Crédito {$numero} actualizada y reenviada a SUNAT.")
            ->with('emitido', ['tipo' => '07', 'id' => $id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
    }
}
