<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Actions\Documents\CreateBoletaAction;
use App\Actions\Documents\CreateInvoiceAction;
use App\Http\Controllers\Api\V1\BoletaController as ApiBoletaController;
use App\Http\Controllers\Api\V1\InvoiceController as ApiInvoiceController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Serie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FacturaController extends Controller
{
    public function index(\Illuminate\Http\Request $request, \App\Services\Documents\DocumentoListingService $listing): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();
        if (! $tenant) {
            return redirect()->route('sunat.configuracion');
        }

        $filtros = ['buscar' => $request->input('buscar')];

        return Inertia::render('sunat/documentos/index', [
            'titulo' => 'Facturas',
            'subtitulo' => 'Comprobantes tipo factura emitidos',
            'nuevo' => ['href' => '/sunat/facturas/nueva', 'label' => 'Nueva factura'],
            'documentos' => $listing->paginar($tenant, 'facturas', $filtros, 25),
            'filtros' => $filtros,
        ]);
    }

    public function create(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion')
                ->with('warning', 'Configura tus credenciales SUNAT primero.');
        }

        $seriesFactura = Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', '01')
            ->where('is_active', true)
            ->get(['id', 'serie', 'tipo_documento', 'correlativo']);

        $seriesBoleta = Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', '03')
            ->where('is_active', true)
            ->get(['id', 'serie', 'tipo_documento', 'correlativo']);

        $clientes = Client::where('tenant_id', $tenant->id)
            ->orderBy('razon_social')
            ->limit(50)
            ->get(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'email']);

        // Pre-fill desde cotización si viene el parámetro
        $cotizacion = null;
        if ($cotId = $request->input('cotizacion_id')) {
            $cot = Quotation::where('tenant_id', $tenant->id)->with('items')->find($cotId);
            if ($cot) {
                $cotizacion = [
                    'numero' => $cot->numero,
                    'moneda' => $cot->tipo_moneda,
                    'cliente' => [
                        'tipo_documento' => $cot->client_tipo_doc,
                        'numero_documento' => $cot->client_num_doc,
                        'razon_social' => $cot->client_razon_social,
                        'direccion' => $cot->client_direccion ?? '',
                    ],
                    'items' => $cot->items->map(fn ($it) => [
                        'descripcion' => $it->descripcion,
                        'unidad' => $it->unidad_medida ?? 'ZZ',
                        'cantidad' => (float) $it->cantidad,
                        'precio_unitario' => (float) ($it->mto_valor_unitario ?? 0),
                        'tip_afe_igv' => $it->tip_afe_igv ?? '10',
                    ])->toArray(),
                    'observacion' => $cot->observacion,
                ];
            }
        }

        return Inertia::render('sunat/facturas/nueva', [
            'tenant' => [
                'ruc' => $tenant->ruc,
                'razon_social' => $tenant->razon_social,
                'direccion' => $tenant->direccion ?? '',
                'environment' => $tenant->environment ?? 'beta',
            ],
            'series_factura' => $seriesFactura,
            'series_boleta' => $seriesBoleta,
            'clientes' => $clientes,
            'tipo_inicial' => $request->input('tipo', 'factura'),
            'cotizacion' => $cotizacion,
        ]);
    }

    public function store(
        Request $request,
        CreateInvoiceAction $createInvoice,
        CreateBoletaAction $createBoleta,
    ): \Illuminate\Http\RedirectResponse {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();

        $tipo = $request->input('tipo_documento', '01');

        // El usuario debe tener permiso para emitir ESE tipo en la empresa activa.
        \Illuminate\Support\Facades\Gate::authorize('emitir', [$tenant, $tipo === '03' ? 'boleta' : 'factura']);

        $enviar = $request->boolean('enviar_automatico', true);

        try {
            if ($tipo === '03') {
                $doc = $createBoleta->execute($tenant, $request->all(), false, $enviar);
                $label = 'Boleta';
            } else {
                $doc = $createInvoice->execute($tenant, $request->all(), $enviar);
                $label = 'Factura';
            }

            $numero = $doc->serie.'-'.str_pad((string) $doc->correlativo, 8, '0', STR_PAD_LEFT);

            $msg = $enviar
                ? "{$label} {$numero} emitida y enviada a SUNAT."
                : "{$label} {$numero} guardada como borrador.";

            // Ir a la LISTA (form limpio) y mostrar el PDF en un modal (flash 'emitido').
            $ruta = $tipo === '03' ? 'sunat.boletas.index' : 'sunat.facturas.index';

            return redirect()->route($ruta)
                ->with('success', $msg)
                ->with('emitido', ['tipo' => $tipo, 'id' => $doc->id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Error al emitir: '.$e->getMessage());
        }
    }

    /**
     * Edita un comprobante RECHAZADO/PENDIENTE (factura o boleta): reutiliza el
     * formulario de "nueva" precargado con los datos del documento. Al guardar se
     * reenvía a SUNAT con la misma serie y correlativo (ver update()).
     */
    public function edit(string $tipo, int $id): Response|\Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();
        if (! $tenant || ! $tenant->sol_user) {
            return redirect()->route('sunat.configuracion');
        }
        abort_unless(in_array($tipo, ['01', '03'], true), 404);

        $doc = ($tipo === '03' ? Boleta::class : Invoice::class)::forTenant($tenant->id)->with('items')->findOrFail($id);

        if (in_array($doc->sunat_status, ['aceptado', 'anulado', 'anulacion_en_proceso'], true)) {
            return redirect()->route('sunat.historial')
                ->with('error', 'Solo se pueden editar comprobantes rechazados o pendientes.');
        }

        $editar = [
            'id' => $doc->id,
            'tipo' => $tipo,
            'serie' => $doc->serie,
            'correlativo' => $doc->correlativo,
            'fecha' => optional($doc->fecha_emision)->format('Y-m-d') ?? (string) $doc->fecha_emision,
            'moneda' => $doc->tipo_moneda ?? 'PEN',
            'tipo_operacion' => $doc->tipo_operacion ?? '0101',
            'forma_pago' => $doc->forma_pago ?? 'Contado',
            'observacion' => $doc->observacion ?? '',
            'cliente' => [
                'tipo_doc' => $doc->client_tipo_doc,
                'num_doc' => $doc->client_num_doc,
                'razon_social' => $doc->client_razon_social,
                'direccion' => $doc->client_direccion ?? '',
            ],
            'items' => $doc->items->map(fn ($i) => [
                'descripcion' => $i->descripcion,
                'unidad' => $i->unidad ?? 'NIU',
                'cantidad' => (float) $i->cantidad,
                'precio_unitario' => (float) ($i->mto_precio_unitario ?? $i->mto_valor_unitario ?? 0),
                'tip_afe_igv' => $i->tip_afe_igv ?? '10',
                'codigo' => $i->codigo ?? '',
                'cod_producto_sunat' => $i->cod_producto_sunat ?? '',
            ])->values(),
        ];

        return Inertia::render('sunat/facturas/nueva', [
            'tenant' => [
                'ruc' => $tenant->ruc,
                'razon_social' => $tenant->razon_social,
                'direccion' => $tenant->direccion ?? '',
                'environment' => $tenant->environment ?? 'beta',
            ],
            'series_factura' => Serie::where('tenant_id', $tenant->id)->where('tipo_documento', '01')->where('is_active', true)->get(['id', 'serie', 'tipo_documento', 'correlativo']),
            'series_boleta' => Serie::where('tenant_id', $tenant->id)->where('tipo_documento', '03')->where('is_active', true)->get(['id', 'serie', 'tipo_documento', 'correlativo']),
            'clientes' => Client::where('tenant_id', $tenant->id)->orderBy('razon_social')->limit(50)->get(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'email']),
            'tipo_inicial' => $tipo === '03' ? 'boleta' : 'factura',
            'lock_tipo' => true,
            'editar' => $editar,
            'post_url' => "/sunat/comprobantes/{$tipo}/{$id}/actualizar",
        ]);
    }

    /**
     * Actualiza y reenvía un comprobante rechazado/pendiente. Delega en el mismo
     * `update` de la API (que recalcula, resetea el estado SUNAT y reencola el envío),
     * para no duplicar esa lógica.
     */
    public function update(Request $request, string $tipo, int $id): \Illuminate\Http\RedirectResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();
        abort_unless(in_array($tipo, ['01', '03'], true), 404);
        Gate::authorize('emitir', [$tenant, $tipo === '03' ? 'boleta' : 'factura']);

        // Petición "interna" para el controlador de la API (resuelve el tenant por merge).
        $apiRequest = Request::create('', 'PUT', $request->all());
        $apiRequest->merge(['tenant' => $tenant]);
        $apiRequest->headers->set('Accept', 'application/json');

        $controller = $tipo === '03' ? app(ApiBoletaController::class) : app(ApiInvoiceController::class);
        $body = $controller->update($apiRequest, $id)->getData(true);

        if (($body['estado'] ?? null) !== 'exito') {
            return back()->withInput()->with('error', $body['mensaje'] ?? 'No se pudo actualizar el comprobante.');
        }

        $label = $tipo === '03' ? 'Boleta' : 'Factura';
        $numero = $body['datos']['numero_completo'] ?? '';

        return redirect()->route($tipo === '03' ? 'sunat.boletas.index' : 'sunat.facturas.index')
            ->with('success', "{$label} {$numero} actualizada y reenviada a SUNAT.")
            ->with('emitido', ['tipo' => $tipo, 'id' => $id, 'numero' => $numero, 'formato' => $request->input('pdf_format', 'a4')]);
    }
}
