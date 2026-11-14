<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateLiquidacionCompraAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLiquidacionCompraRequest;
use App\Http\Traits\ApiResponse;
use App\Http\Traits\CachesPdf;
use App\Jobs\SendLiquidacionCompraToSunat;
use App\Jobs\SendReversionToSunat;
use App\Models\PurchaseSettlement;
use App\Models\VoidedDocument;
use App\Services\Pdf\PdfFormatConfig;
use App\Services\Pdf\PdfGeneratorService;
use App\Services\Storage\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Liquidación de compra electrónica (tipo 04). Emisión, consulta, descargas, envío manual, pagos
 * al vendedor y reporte de retenciones. El vendedor es una persona natural sin RUC (DNI/CE).
 */
class LiquidacionCompraController extends Controller
{
    use ApiResponse, CachesPdf;

    public function store(StoreLiquidacionCompraRequest $request, CreateLiquidacionCompraAction $action): JsonResponse
    {
        $tenant = $request->get('tenant');
        $enviarAuto = $request->boolean('enviar_automatico', true);

        try {
            $settlement = $action->execute($tenant, $request->validated(), $enviarAuto);
        } catch (\Throwable $e) {
            return $this->error('Error al crear la liquidación de compra: '.$e->getMessage(), 500);
        }

        return $this->created(
            $this->resource($settlement, true),
            $enviarAuto
                ? 'Liquidación de compra creada y encolada para envío a SUNAT.'
                : 'Liquidación de compra creada en estado pendiente. Use POST /liquidaciones-compra/{id}/enviar para enviarla a SUNAT.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->get('tenant');
        $query = PurchaseSettlement::forTenant($tenant->id);

        if ($request->filled('sunat_status')) {
            $query->where('sunat_status', $request->input('sunat_status'));
        }
        if ($request->filled('serie')) {
            $query->where('serie', $request->input('serie'));
        }
        if ($request->filled('vendedor_num_doc')) {
            $query->where('vendedor_num_doc', $request->input('vendedor_num_doc'));
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_emision', '>=', $request->input('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_emision', '<=', $request->input('fecha_hasta'));
        }

        $perPage = min(max((int) $request->input('por_pagina', 15), 1), 100);
        $paginator = $query->orderByDesc('fecha_emision')->orderByDesc('id')->paginate($perPage);

        return $this->success([
            'datos' => collect($paginator->items())->map(fn ($s) => $this->resource($s, false)),
            'paginacion' => [
                'pagina_actual' => $paginator->currentPage(),
                'ultima_pagina' => $paginator->lastPage(),
                'por_pagina' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->success($this->resource($this->find($request, $id), true));
    }

    public function enviar(Request $request, int $id): JsonResponse
    {
        return $this->despachar($request, $id, false);
    }

    public function reenviar(Request $request, int $id): JsonResponse
    {
        return $this->despachar($request, $id, true);
    }

    private function despachar(Request $request, int $id, bool $reenviar): JsonResponse
    {
        $settlement = $this->find($request, $id);
        $estado = $settlement->sunat_status;

        if (in_array($estado, ['aceptado', 'anulacion_en_proceso', 'anulado'], true)) {
            return $this->error("Esta liquidación de compra ya fue aceptada por SUNAT (estado: {$estado}).", 422);
        }
        if ($reenviar && ! in_array($estado, ['rechazado', 'pendiente'], true)) {
            return $this->error("Solo se reenvían liquidaciones pendientes o rechazadas (estado: {$estado}).", 422);
        }

        $settlement->update(['sunat_status' => 'enviado', 'sunat_code' => null, 'sunat_description' => null]);
        SendLiquidacionCompraToSunat::dispatch($settlement->id);

        return $this->success($this->resource($settlement->fresh(), true), 'Liquidación de compra enviada a SUNAT.');
    }

    public function xml(Request $request, int $id): \Illuminate\Http\Response|JsonResponse
    {
        $settlement = $this->find($request, $id);
        $content = (new DocumentStorageService)->getXmlContent($settlement);
        if (! $content) {
            return $this->error('XML no disponible', 404);
        }

        return response($content, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$settlement->numero_completo}.xml\"",
        ]);
    }

    public function cdr(Request $request, int $id): \Illuminate\Http\Response|JsonResponse
    {
        $settlement = $this->find($request, $id);
        $content = (new DocumentStorageService)->getCdrContent($settlement);
        if (! $content) {
            return $this->error('CDR no disponible', 404);
        }

        return response($content, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"R-{$settlement->numero_completo}.zip\"",
        ]);
    }

    public function pdf(Request $request, int|string $id): \Illuminate\Http\Response|JsonResponse
    {
        $settlement = $this->find($request, (int) $id);
        $tenant = $request->get('tenant');
        $formatStr = $request->input('formato', $request->input('format', config('pdf.default_format', 'a4')));

        try {
            $format = PdfFormatConfig::from($formatStr);
        } catch (\ValueError) {
            return $this->error('Formato inválido. Opciones: a4, a5, ticket-80, ticket-58', 422);
        }

        $content = $this->getCachedPdfContent($settlement, $formatStr);
        if (! $content) {
            $content = app(PdfGeneratorService::class)->generate($settlement, $tenant, $format);
            $this->cachePdfContent($settlement, $formatStr, $content);
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$settlement->numero_completo}.pdf\"",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    // ---------------------------------------------------------------- reversión (RR)

    /**
     * Revierte una liquidación de compra aceptada mediante un Resumen de reversiones (RR).
     * Requisitos (RS 097-2012/SUNAT art. 29-F, RS 000047-2026/SUNAT): CDR aceptado; causal
     * (nombre/documento del vendedor o producto); sin pagos registrados; hasta el 5.º día
     * calendario siguiente a la emisión. El correlativo de la liquidación no se reutiliza.
     */
    public function reversion(Request $request, int $id): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'causal' => 'required|string|in:vendedor_nombre,vendedor_documento,producto',
            'motivo' => 'required|string|min:3|max:100',
        ])->validate();

        $settlement = $this->find($request, $id);
        $tenant = $request->get('tenant');
        $estado = $settlement->sunat_status;

        $errores = [];
        $hoy = \Carbon\Carbon::now('America/Lima')->startOfDay();
        $emision = $settlement->fecha_emision->copy()->startOfDay();
        $dias = (int) config('liquidacion.reversion_dias', 5);

        if (in_array($estado, ['anulacion_en_proceso', 'anulado'], true)) {
            $errores[] = "La liquidación ya tiene una reversión (estado: {$estado}) (SUNAT 2751).";
        } elseif ($estado !== 'aceptado') {
            $errores[] = "Solo se revierte una liquidación con CDR aceptado (estado: {$estado}) (SUNAT 2398).";
        } else {
            if ($hoy->gt($emision->copy()->addDays($dias))) {
                $errores[] = "El plazo de reversión venció: hasta el {$dias}.º día calendario siguiente a la emisión ("
                    .$emision->copy()->addDays($dias)->format('Y-m-d').').';
            }
            if ((float) $settlement->monto_pagado > 0 || $settlement->payments()->exists()) {
                $errores[] = 'La liquidación tiene pagos registrados: no se puede revertir (SUNAT 2471).';
            }
        }

        if (! empty($errores)) {
            return $this->error('No se puede revertir: '.implode(' ', $errores), 422, $errores);
        }

        $fechaCom = $hoy->format('Y-m-d');
        $fechaId = str_replace('-', '', $fechaCom);
        $last = VoidedDocument::where('tenant_id', $tenant->id)
            ->where('identifier', 'like', "RR-{$fechaId}-%")->max('correlativo') ?? 0;
        $correlativo = str_pad((string) ((int) $last + 1), 3, '0', STR_PAD_LEFT);
        $identifier = "RR-{$fechaId}-{$correlativo}";

        $detalle = [[
            'tipo_documento' => '04',
            'serie' => $settlement->serie,
            'correlativo' => (string) $settlement->correlativo,
            'motivo' => trim((string) $data['motivo']),
            'causal' => $data['causal'],
        ]];

        $voided = VoidedDocument::create([
            'tenant_id' => $tenant->id,
            'identifier' => $identifier,
            'correlativo' => $correlativo,
            'fecha_generacion' => $emision->format('Y-m-d'),
            'fecha_comunicacion' => $fechaCom,
            'total_documentos' => 1,
            'detalles' => $detalle,
            'sunat_status' => 'pendiente',
        ]);

        $settlement->update(['sunat_status' => 'anulacion_en_proceso']);
        SendReversionToSunat::dispatch($voided->id);
        $voided->update(['sunat_status' => 'enviado']);

        return $this->created([
            'id_liquidacion' => $settlement->id,
            'voided_id' => $voided->id,
            'identifier' => $identifier,
            'sunat_status' => 'enviado',
            'consulta_estado' => url("/api/v1/anulaciones/{$voided->id}/estado"),
        ], 'Reversión encolada para envío a SUNAT. La liquidación queda en anulacion_en_proceso hasta la respuesta.');
    }

    // ---------------------------------------------------------------- pagos al vendedor

    public function pagosStore(Request $request, int $id): JsonResponse
    {
        $settlement = $this->find($request, $id);
        if (in_array($settlement->sunat_status, ['anulado', 'anulacion_en_proceso'], true)) {
            return $this->error('La liquidación de compra está revertida o en proceso de reversión: no admite pagos.', 422);
        }

        $data = $request->all();
        if (isset($data['metodo']) && ! isset($data['pagos'])) {
            $data['pagos'] = [array_intersect_key($data, array_flip(['metodo', 'monto', 'referencia', 'notas']))];
        }
        $validated = Validator::make($data, [
            'pagos' => 'required|array|min:1',
            'pagos.*.metodo' => 'required|string|max:20',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.referencia' => 'nullable|string|max:100',
            'pagos.*.notas' => 'nullable|string|max:255',
        ])->validate();

        DB::transaction(function () use ($settlement, $validated) {
            foreach ($validated['pagos'] as $pago) {
                $settlement->payments()->create([
                    'tenant_id' => $settlement->tenant_id,
                    'metodo' => $pago['metodo'],
                    'monto' => round((float) $pago['monto'], 2),
                    'referencia' => $pago['referencia'] ?? null,
                    'notas' => $pago['notas'] ?? null,
                ]);
            }
            $this->recalcularPagos($settlement);
        });

        return $this->pagosIndex($request, $id, 'Pagos registrados correctamente');
    }

    public function pagosIndex(Request $request, int $id, ?string $mensaje = null): JsonResponse
    {
        $settlement = $this->find($request, $id);
        $pagos = $settlement->payments()->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'metodo' => $p->metodo,
            'monto' => (float) $p->monto,
            'referencia' => $p->referencia,
            'notas' => $p->notas,
            'creado_en' => optional($p->created_at)->toIso8601String(),
        ]);

        return $this->success([
            'pagos' => $pagos,
            'estado_pago' => $settlement->payment_status,
            'monto_pagado' => (float) $settlement->monto_pagado,
            'total_documento' => (float) $settlement->mto_imp_neto,
        ], $mensaje ?? 'OK');
    }

    public function pagosDestroy(Request $request, int $id, int $paymentId): JsonResponse
    {
        $settlement = $this->find($request, $id);
        $deleted = $settlement->payments()->where('id', $paymentId)->delete();
        if (! $deleted) {
            return $this->error('Pago no encontrado', 404);
        }
        $this->recalcularPagos($settlement);

        return $this->success([
            'estado_pago' => $settlement->fresh()->payment_status,
            'monto_pagado' => (float) $settlement->fresh()->monto_pagado,
        ], 'Pago eliminado correctamente');
    }

    private function recalcularPagos(PurchaseSettlement $settlement): void
    {
        $pagado = (float) $settlement->payments()->sum('monto');
        $total = (float) $settlement->mto_imp_neto;
        $status = $pagado <= 0 ? 'pendiente' : ($pagado < $total ? 'parcial' : 'pagado');
        $settlement->update(['monto_pagado' => round($pagado, 2), 'payment_status' => $status]);
    }

    // ---------------------------------------------------------------- reporte de retenciones

    public function reporteRetenciones(Request $request): JsonResponse
    {
        $tenant = $request->get('tenant');
        $validated = Validator::make($request->all(), [
            'periodo' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ])->validate();

        $periodo = $validated['periodo'];
        $desde = $periodo.'-01';
        $rows = PurchaseSettlement::forTenant($tenant->id)
            ->whereYear('fecha_emision', substr($periodo, 0, 4))
            ->whereMonth('fecha_emision', substr($periodo, 5, 2))
            ->whereIn('sunat_status', ['aceptado', 'anulacion_en_proceso'])
            ->orderBy('fecha_emision')->orderBy('id')
            ->get();

        $tot = ['base_imponible_igv' => 0, 'igv_retenido' => 0, 'base_renta' => 0, 'renta_retenida' => 0, 'neto_pagado_vendedor' => 0];
        $liquidaciones = $rows->map(function ($s) use (&$tot) {
            $tot['base_imponible_igv'] += (float) $s->mto_oper_gravadas;
            $tot['igv_retenido'] += (float) $s->mto_igv;
            $tot['base_renta'] += (float) $s->mto_base_renta;
            $tot['renta_retenida'] += (float) $s->mto_retencion_renta;
            $tot['neto_pagado_vendedor'] += (float) $s->mto_imp_neto;

            return [
                'id' => $s->id,
                'numero' => $s->serie.'-'.$s->correlativo,
                'fecha_emision' => $s->fecha_emision->format('Y-m-d'),
                'vendedor' => $this->vendedor($s),
                'base_imponible_igv' => (float) $s->mto_oper_gravadas,
                'igv_retenido' => (float) $s->mto_igv,
                'base_renta' => (float) $s->mto_base_renta,
                'porcentaje_renta' => (float) $s->porcentaje_renta,
                'renta_retenida' => (float) $s->mto_retencion_renta,
                'neto_pagado_vendedor' => (float) $s->mto_imp_neto,
                'estado_sunat' => $s->sunat_status,
                'estado_pago' => $s->payment_status,
            ];
        });

        return $this->success([
            'periodo' => $periodo,
            'liquidaciones' => $liquidaciones,
            'totales' => array_merge(['cantidad' => $rows->count()], array_map(fn ($v) => round($v, 2), $tot)),
            'nota' => 'Desde el periodo julio de 2026 el IGV retenido se paga por SUNAT Virtual vinculado a cada liquidación de compra '
                .'y se declara en el Formulario Virtual 617 (RS 000047-2026/SUNAT). La renta retenida se declara y paga en el Formulario 617 del periodo.',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function find(Request $request, int $id): PurchaseSettlement
    {
        $tenant = $request->get('tenant');

        return PurchaseSettlement::with('items')->forTenant($tenant->id)->findOrFail($id);
    }

    private function vendedor(PurchaseSettlement $s): array
    {
        return [
            'tipo_doc' => $s->vendedor_tipo_doc,
            'num_doc' => $s->vendedor_num_doc,
            'nombre' => $s->vendedor_nombre,
            'direccion' => $s->vendedor_direccion,
            'ubigeo' => $s->vendedor_ubigeo,
            'tipo_domicilio' => $s->vendedor_tipo_domicilio,
        ];
    }

    private function resource(PurchaseSettlement $s, bool $withItems): array
    {
        $m = [
            'id' => $s->id,
            'tipo_documento' => '04',
            'serie' => $s->serie,
            'correlativo' => $s->correlativo,
            'numero_completo' => $s->serie.'-'.$s->correlativo,
            'fecha_emision' => $s->fecha_emision->format('Y-m-d H:i:s'),
            'tipo_operacion' => $s->tipo_operacion,
            'tipo_moneda' => $s->tipo_moneda,
            'vendedor' => $this->vendedor($s),
            'lugar' => [
                'direccion' => $s->lugar_direccion,
                'ubigeo' => $s->lugar_ubigeo,
                'tipo' => $s->lugar_tipo,
            ],
            'productos_agropecuarios' => (bool) $s->productos_agropecuarios,
            'totales' => [
                'gravadas' => (float) $s->mto_oper_gravadas,
                'exoneradas' => (float) $s->mto_oper_exoneradas,
                'inafectas' => (float) $s->mto_oper_inafectas,
                'porcentaje_igv' => (float) $s->porcentaje_igv,
                'igv' => (float) $s->mto_igv,
                'valor_venta' => (float) $s->valor_venta,
                'sub_total' => (float) $s->sub_total,
                'base_renta' => (float) $s->mto_base_renta,
                'porcentaje_renta' => (float) $s->porcentaje_renta,
                'retencion_renta' => (float) $s->mto_retencion_renta,
                'neto_a_pagar' => (float) $s->mto_imp_neto,
            ],
            'guias' => $s->guias,
            'placa_vehiculo' => $s->placa_vehiculo,
            'leyenda' => $s->leyenda,
            'observacion' => $s->observacion,
            'sunat' => [
                'estado' => $s->sunat_status,
                'codigo' => $s->sunat_code,
                'descripcion' => $s->sunat_description,
                'notas' => $s->sunat_notes,
                'hash_cpe' => $s->hash_cpe,
                'fecha_cdr' => optional($s->cdr_fecha)->format('Y-m-d H:i:s'),
            ],
            'archivos' => [
                'xml' => $s->xml_path ? url("/api/v1/liquidaciones-compra/{$s->id}/xml") : null,
                'cdr' => $s->cdr_path ? url("/api/v1/liquidaciones-compra/{$s->id}/cdr") : null,
            ],
            'estado_pago' => $s->payment_status,
            'monto_pagado' => (float) $s->monto_pagado,
            'enviado_en' => optional($s->sent_at)->toIso8601String(),
            'creado_en' => optional($s->created_at)->toIso8601String(),
        ];

        if ($withItems) {
            $m['items'] = $s->items->map(fn ($it) => [
                'codigo' => $it->codigo,
                'cod_producto_sunat' => $it->cod_producto_sunat,
                'descripcion' => $it->descripcion,
                'unidad' => $it->unidad,
                'cantidad' => (float) $it->cantidad,
                'valor_unitario' => (float) $it->valor_unitario,
                'precio_unitario' => (float) $it->precio_unitario,
                'tip_afe_igv' => $it->tip_afe_igv,
                'valor_venta' => (float) $it->valor_venta,
                'igv' => (float) $it->igv,
                'porcentaje_renta' => (float) $it->porcentaje_renta,
                'renta' => (float) $it->renta,
                'oro' => $it->oro,
            ]);
        }

        return $m;
    }
}
