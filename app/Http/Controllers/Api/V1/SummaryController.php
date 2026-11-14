<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSummaryRequest;
use App\Http\Traits\ApiResponse;
use App\Jobs\SendSummaryToSunat;
use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\Summary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SummaryController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->get('tenant');

        $query = Summary::where('tenant_id', $tenant->id);

        if ($mes = $request->query('mes')) {
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $ymExpr = $driver === 'pgsql'
                ? "to_char(fecha_envio, 'YYYY-MM')"
                : "DATE_FORMAT(fecha_envio, '%Y-%m')";
            $query->whereRaw("{$ymExpr} = ?", [$mes]);
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        $summaries = $query->orderByDesc('created_at')
            ->paginate($request->query('por_pagina', 15));

        return response()->json([
            'estado' => 'exito',
            'datos' => $summaries->map(fn (Summary $s) => [
                'id' => $s->id,
                'identifier' => $s->identifier,
                'tipo' => $s->tipo,
                'fecha_referencia' => $s->fecha_referencia->format('Y-m-d'),
                'fecha_envio' => $s->fecha_envio->format('Y-m-d'),
                'total_documentos' => $s->total_documentos,
                'ticket' => $s->ticket,
                'estado_sunat' => $s->sunat_status,
                'codigo_sunat' => $s->sunat_code,
                'descripcion_sunat' => $s->sunat_description,
                'creado_en' => $s->created_at->toIso8601String(),
            ]),
            'paginacion' => [
                'total' => $summaries->total(),
                'pagina_actual' => $summaries->currentPage(),
                'ultima_pagina' => $summaries->lastPage(),
            ],
        ]);
    }

    /**
     * Generar y encolar resumen diario de boletas.
     *
     * Modos:
     * 1. Enviar boletas pendientes: POST { "fecha_resumen": "2026-03-05" }
     * 2. Anular boletas: POST { "fecha_resumen": "2026-03-05", "anular": [{"id": 15, "motivo": "..."}, ...] }
     */
    public function store(StoreSummaryRequest $request): JsonResponse
    {
        $tenant = $request->get('tenant');
        $fechaResumen = Carbon::parse($request->input('fecha_resumen'));
        $enviarAuto = $request->boolean('enviar_automatico', true);

        $hoy = Carbon::today('America/Lima');
        $limiteAnterior = $hoy->copy()->subDays(7);
        if ($fechaResumen->lt($limiteAnterior->startOfDay()) || $fechaResumen->gt($hoy->endOfDay())) {
            return $this->error(
                'SUNAT solo permite resumen diario del mismo día de emisión o hasta 7 días calendario después. Fecha límite: '.$limiteAnterior->format('Y-m-d'),
                422
            );
        }

        $isAnulacion = ! empty($request->input('anular'));

        try {
            if ($isAnulacion) {
                $anularItems = $request->input('anular');
                $documentIds = collect($anularItems)->pluck('id')->toArray();

                $boletas = Boleta::where('tenant_id', $tenant->id)
                    ->whereIn('id', $documentIds)
                    ->where('sunat_status', 'aceptado')
                    ->get();

                if ($boletas->isEmpty()) {
                    return $this->error('No se encontraron boletas aceptadas por SUNAT para anular.', 422);
                }

                // Validaciones adicionales: plazo 7 días por boleta, NC asociada, duplicado en proceso.
                $anulErrors = [];
                foreach ($boletas as $b) {
                    $ref = $b->serie.'-'.$b->correlativo;

                    $fechaEmision = $b->fecha_emision instanceof Carbon
                        ? $b->fecha_emision
                        : Carbon::parse((string) $b->fecha_emision);

                    if ($fechaEmision->lt($limiteAnterior->copy()->startOfDay())) {
                        $anulErrors[] = "Boleta {$ref} ya pasó el plazo de 7 días para anulación (emitida el {$fechaEmision->format('Y-m-d')}).";

                        continue;
                    }

                    $hasNC = CreditNote::where('tenant_id', $tenant->id)
                        ->where('doc_afectado_tipo', '03')
                        ->where('doc_afectado_serie', $b->serie)
                        ->where('doc_afectado_correlativo', $b->correlativo)
                        ->exists();

                    if ($hasNC) {
                        $anulErrors[] = "La boleta {$ref} tiene una nota de crédito asociada. Usa la NC en vez de anularla.";

                        continue;
                    }

                    $duplicate = Summary::where('tenant_id', $tenant->id)
                        ->where('tipo', 'anulacion')
                        ->whereIn('sunat_status', ['pendiente', 'enviado', 'aceptado'])
                        ->whereJsonContains('document_ids', $b->id)
                        ->first();

                    if ($duplicate) {
                        $anulErrors[] = "Ya existe un resumen de anulación para la boleta {$ref} ({$duplicate->identifier}).";
                    }
                }

                if (! empty($anulErrors)) {
                    return response()->json([
                        'estado' => 'error',
                        'mensaje' => 'No se puede anular: '.implode(' ', $anulErrors),
                        'errores' => $anulErrors,
                    ], 422);
                }
            } else {
                $boletas = Boleta::where('tenant_id', $tenant->id)
                    ->whereDate('fecha_emision', $fechaResumen)
                    ->where('sunat_status', 'pendiente')
                    ->get();

                if ($boletas->isEmpty()) {
                    return $this->error(
                        'No hay boletas pendientes para la fecha '.$fechaResumen->format('Y-m-d').'.',
                        422
                    );
                }

                // Anti-duplicado: una boleta sigue "pendiente" hasta que SUNAT acepta el
                // ticket (asíncrono). Si ya está en un resumen de envío de esta fecha que está
                // en vuelo o aceptado, no la reenviamos o SUNAT devuelve el código 2282
                // "Existe documento ya informado anteriormente".
                $yaEnResumen = Summary::where('tenant_id', $tenant->id)
                    ->where('tipo', 'envio')
                    ->whereDate('fecha_referencia', $fechaResumen->format('Y-m-d'))
                    ->whereIn('sunat_status', ['pendiente', 'enviado', 'aceptado'])
                    ->get()
                    ->flatMap(fn (Summary $s) => $s->document_ids ?? [])
                    ->unique();

                if ($yaEnResumen->isNotEmpty()) {
                    $boletas = $boletas->reject(fn (Boleta $b) => $yaEnResumen->contains($b->id))->values();
                }

                if ($boletas->isEmpty()) {
                    return $this->error(
                        'Las boletas de la fecha '.$fechaResumen->format('Y-m-d').' ya están incluidas en un resumen (en proceso o aceptado). Consulta su estado en vez de generar otro — reenviarlas da el error SUNAT 2282 "documento ya informado anteriormente".',
                        422
                    );
                }
            }

            // El correlativo se numera por FECHA DE ENVÍO, que es la que entra en
            // el identificador. Numerarlo por fecha de referencia —como se hacía—
            // produce identificadores repetidos: el día que se envía el resumen
            // atrasado de ayer junto con el de hoy, cada uno pide su correlativo
            // a un contador distinto, los dos devuelven 001 y los dos salen como
            // RC-{hoy}-001. Para SUNAT ese identificador ES el resumen, así que
            // el segundo viaja como duplicado y su ticket nunca resuelve.
            $fechaEnvio = Carbon::now('America/Lima')->format('Y-m-d');
            $correlativo = $this->generateCorrelativo($tenant, $fechaEnvio);
            $fechaId = str_replace('-', '', $fechaEnvio);
            $identifier = "RC-{$fechaId}-{$correlativo}";

            $summary = Summary::create([
                'tenant_id' => $tenant->id,
                'identifier' => $identifier,
                'correlativo' => $correlativo,
                'fecha_referencia' => $fechaResumen->format('Y-m-d'),
                'fecha_envio' => $fechaEnvio,
                'total_documentos' => $boletas->count(),
                'tipo' => $isAnulacion ? 'anulacion' : 'envio',
                'document_ids' => $boletas->pluck('id')->toArray(),
                'sunat_status' => 'pendiente',
            ]);

            if ($isAnulacion) {
                Boleta::whereIn('id', $boletas->pluck('id')->toArray())
                    ->update(['sunat_status' => 'anulacion_en_proceso']);
            }

            if ($enviarAuto) {
                SendSummaryToSunat::dispatch($summary->id);
                $summary->update(['sunat_status' => 'enviado']);
            }

            $messageBase = $isAnulacion ? 'Resumen de anulación' : 'Resumen diario';
            $message = $enviarAuto
                ? "{$messageBase} encolado para envío a SUNAT."
                : "{$messageBase} creado en estado pendiente. Use POST /resumenes/{id}/enviar para enviarlo a SUNAT.";

            return response()->json([
                'estado' => 'exito',
                'mensaje' => $message,
                'datos' => [
                    'id_resumen' => $summary->id,
                    'identifier' => $identifier,
                    'fecha_envio' => $fechaEnvio,
                    'fecha_documentos' => $fechaResumen->format('Y-m-d'),
                    'correlativo' => $correlativo,
                    'accion' => $isAnulacion ? 'anulacion' : 'envio',
                    'total_documentos' => $boletas->count(),
                    'estado_sunat' => $enviarAuto ? 'enviado' : 'pendiente',
                    'documentos' => $boletas->map(fn (Boleta $doc) => $this->documentoResumen($doc, $isAnulacion))->toArray(),
                    'consulta_estado' => url("/api/v1/resumenes/{$summary->id}/estado"),
                ],
            ], 201);
        } catch (\Throwable $e) {
            return $this->error('Error al crear resumen: '.$e->getMessage(), 500);
        }
    }

    /**
     * Detalle de una boleta tal como viaja en el XML del Resumen Diario
     * (SummaryDocuments): tipo, serie-correlativo, estado (1=adicionar,
     * 3=anulado), documento del cliente y los montos por tipo de operación.
     */
    private function documentoResumen(Boleta $doc, bool $isAnulacion): array
    {
        return [
            'id' => $doc->id,
            'tipo_documento' => '03',
            // Mismo formato que el cbc:ID del XML del resumen (serie-correlativo, sin relleno).
            'serie_nro' => $doc->serie.'-'.$doc->correlativo,
            'estado' => $isAnulacion ? '3' : '1',
            'cliente' => [
                'tipo_doc' => $doc->client_tipo_doc,
                'num_doc' => $doc->client_num_doc,
            ],
            'mto_oper_gravadas' => (float) $doc->mto_oper_gravadas,
            'mto_oper_exoneradas' => (float) $doc->mto_oper_exoneradas,
            'mto_oper_inafectas' => (float) $doc->mto_oper_inafectas,
            'mto_oper_gratuitas' => (float) $doc->mto_oper_gratuitas,
            'mto_igv' => (float) $doc->mto_igv,
            'total' => (float) $doc->mto_imp_venta,
            'estado_sunat' => $doc->sunat_status,
        ];
    }

    public function enviar(Request $request, int $id): JsonResponse
    {
        $tenant = $request->get('tenant');
        $summary = Summary::where('tenant_id', $tenant->id)->findOrFail($id);

        if (in_array($summary->sunat_status, ['aceptado'], true)) {
            return $this->error('Este resumen ya fue aceptado por SUNAT.', 422);
        }

        $summary->update([
            'sunat_status' => 'pendiente',
            'sunat_code' => null,
            'sunat_description' => null,
        ]);

        SendSummaryToSunat::dispatch($summary->id);
        $summary->update(['sunat_status' => 'enviado']);

        return response()->json([
            'estado' => 'exito',
            'mensaje' => 'Resumen enviado a SUNAT.',
            'datos' => [
                'id_resumen' => $summary->id,
                'identifier' => $summary->identifier,
                'estado_sunat' => 'enviado',
                'consulta_estado' => url("/api/v1/resumenes/{$summary->id}/estado"),
            ],
        ]);
    }

    public function checkStatus(Request $request, int $id): JsonResponse
    {
        $tenant = $request->get('tenant');

        $summary = Summary::where('tenant_id', $tenant->id)->findOrFail($id);

        $boletas = Boleta::whereIn('id', $summary->document_ids ?? [])
            ->get([
                'id', 'serie', 'correlativo', 'client_tipo_doc', 'client_num_doc',
                'mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas',
                'mto_oper_gratuitas', 'mto_igv', 'mto_imp_venta',
                'sunat_status', 'sunat_code', 'sunat_description',
            ]);

        return response()->json([
            'estado' => 'exito',
            'datos' => [
                'id_resumen' => $summary->id,
                'identifier' => $summary->identifier,
                'ticket' => $summary->ticket,
                'estado_sunat' => $summary->sunat_status,
                'codigo_sunat' => $summary->sunat_code,
                'descripcion_sunat' => $summary->sunat_description,
                'notas_sunat' => $summary->sunat_notes,
                'tipo' => $summary->tipo,
                'total_documentos' => $summary->total_documentos,
                'documentos' => $boletas->map(fn (Boleta $doc) => $this->documentoResumen($doc, $summary->tipo === 'anulacion'))->toArray(),
            ],
        ]);
    }

    public function xml(Request $request, int $id): \Illuminate\Http\Response|JsonResponse
    {
        $tenant = $request->get('tenant');
        $summary = Summary::where('tenant_id', $tenant->id)->findOrFail($id);

        if (! $summary->xml_path) {
            $motivo = match ($summary->sunat_status) {
                'pendiente' => 'El resumen aún no fue procesado (estado: pendiente). Espera a que el worker lo envíe a SUNAT.',
                'enviado' => 'El resumen fue enviado pero el XML no está disponible aún. Consulta el estado con GET /resumenes/{id}/estado.',
                'rechazado' => 'El resumen fue rechazado por SUNAT y el XML no pudo guardarse. Código: '.($summary->sunat_code ?? 'N/A').'.',
                default => 'XML no generado (estado: '.$summary->sunat_status.').',
            };

            return $this->error($motivo, 404);
        }

        if (! \App\Support\Storage\Comprobantes::exists($summary->xml_path)) {
            return $this->error(
                'El XML fue generado pero el archivo no se encuentra en disco. Ruta esperada: '.$summary->xml_path,
                404
            );
        }

        $content = \App\Support\Storage\Comprobantes::get($summary->xml_path);

        return response($content, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$summary->identifier}.xml\"",
        ]);
    }

    public function cdr(Request $request, int $id): \Illuminate\Http\Response|JsonResponse
    {
        $tenant = $request->get('tenant');
        $summary = Summary::where('tenant_id', $tenant->id)->findOrFail($id);

        if (! $summary->cdr_path || ! \App\Support\Storage\Comprobantes::exists($summary->cdr_path)) {
            return $this->error('CDR no disponible', 404);
        }

        $content = \App\Support\Storage\Comprobantes::get($summary->cdr_path);

        return response($content, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"R-{$summary->identifier}.zip\"",
        ]);
    }

    /**
     * Siguiente correlativo del día de ENVÍO.
     *
     * Se deriva de la base, no de un contador en caché: el contador anterior
     * vivía en Redis con 48h de TTL, así que un reinicio o un flush lo devolvía
     * a 001 y el identificador volvía a chocar con uno ya enviado a SUNAT.
     *
     * El lock cubre el hueco entre leer el máximo y grabar la fila. Si aun así
     * dos peticiones se cruzaran, el índice único de `summaries` es la garantía
     * final: la segunda falla en vez de crear un duplicado silencioso.
     */
    private function generateCorrelativo($tenant, string $fechaEnvio): string
    {
        $siguiente = fn (): int => 1 + (int) Summary::where('tenant_id', $tenant->id)
            ->where('fecha_envio', $fechaEnvio)
            ->pluck('correlativo')
            ->map(fn ($c): int => (int) $c)
            ->max();

        $correlativo = Cache::lock("summary_correlativo:{$tenant->id}:{$fechaEnvio}", 10)
            ->block(5, $siguiente);

        return str_pad((string) $correlativo, 3, '0', STR_PAD_LEFT);
    }
}
