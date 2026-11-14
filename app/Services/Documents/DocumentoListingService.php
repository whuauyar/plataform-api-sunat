<?php

namespace App\Services\Documents;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use App\Models\Perception;
use App\Models\Retention;
use App\Models\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Punto único para listar comprobantes en formato uniforme.
 * Lo usan el Historial (todos los tipos) y los listados por tipo de "Emitir".
 *
 * `paginar()` hace la paginación y la búsqueda EN EL SERVIDOR, así que todos los
 * comprobantes son alcanzables (antes se cargaban solo los últimos 300 y la
 * búsqueda era en el navegador, dejando invisibles los correlativos más antiguos).
 */
class DocumentoListingService
{
    /** Cap por tipo para la vista "todos" (overview); para ver todo un tipo, se filtra por ese tipo. */
    private const CAP_TODOS = 1000;

    /**
     * Listado paginado y buscable en el servidor.
     *
     * @param  array{estado?:?string,desde?:?string,hasta?:?string,cliente?:?string,buscar?:?string}  $filtros
     */
    public function paginar(Tenant $tenant, string $tipo = 'todos', array $filtros = [], int $porPagina = 25): LengthAwarePaginator
    {
        $fuentes = $this->fuentes($tenant, $filtros);

        // Un tipo concreto → paginación real en la BD: TODO el histórico es alcanzable.
        if (array_key_exists($tipo, $fuentes)) {
            [$query, $mapper] = $fuentes[$tipo];

            return $query->paginate($porPagina)->through($mapper)->withQueryString();
        }

        // "todos" → mezcla acotada de los tipos, paginada en memoria (overview).
        $items = collect();
        foreach ($fuentes as [$query, $mapper]) {
            $items = $items->concat($query->limit(self::CAP_TODOS)->get()->map($mapper));
        }
        $items = $items->sortByDesc('fecha')->values();

        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $slice = $items->forPage($pagina, $porPagina)->values();

        return new LengthAwarePaginator($slice, $items->count(), $porPagina, $pagina, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Listado plano (array), compatible hacia atrás. Mantiene el tope histórico de 300
     * para las pantallas que aún no usan paginación de servidor.
     *
     * @param  array{estado?:?string,desde?:?string,hasta?:?string,cliente?:?string,buscar?:?string}  $filtros
     * @return array<int,array<string,mixed>>
     */
    public function listar(Tenant $tenant, string $tipo = 'todos', array $filtros = []): array
    {
        $fuentes = $this->fuentes($tenant, $filtros);
        $seleccion = array_key_exists($tipo, $fuentes) ? [$tipo] : array_keys($fuentes);

        $merged = collect();
        foreach ($seleccion as $key) {
            [$query, $mapper] = $fuentes[$key];
            $merged = $merged->concat($query->limit(300)->get()->map($mapper));
        }

        return $merged->sortByDesc('fecha')->take(300)->values()->all();
    }

    /**
     * Para cada tipo: [query builder con filtros aplicados (sin ejecutar), mapper].
     *
     * @return array<string,array{0:\Illuminate\Database\Eloquent\Builder,1:\Closure}>
     */
    private function fuentes(Tenant $tenant, array $filtros): array
    {
        return [
            'facturas' => [$this->aplicarFiltros(Invoice::forTenant($tenant->id), 'client_razon_social', $filtros), fn ($d) => $this->mapDoc($d, '01')],
            'boletas' => [$this->aplicarFiltros(Boleta::forTenant($tenant->id), 'client_razon_social', $filtros), fn ($d) => $this->mapDoc($d, '03')],
            'notas-credito' => [$this->aplicarFiltros(CreditNote::forTenant($tenant->id), 'client_razon_social', $filtros), fn ($d) => $this->mapDoc($d, '07')],
            'notas-debito' => [$this->aplicarFiltros(DebitNote::forTenant($tenant->id), 'client_razon_social', $filtros), fn ($d) => $this->mapDoc($d, '08')],
            'guias' => [$this->aplicarFiltros(DispatchGuide::forTenant($tenant->id), 'destinatario_razon_social', $filtros), fn ($d) => $this->mapGuia($d)],
            'retenciones' => [$this->aplicarFiltros(Retention::forTenant($tenant->id), 'proveedor_razon_social', $filtros), fn ($d) => $this->mapRetPerc($d, '20')],
            'percepciones' => [$this->aplicarFiltros(Perception::forTenant($tenant->id), 'cliente_razon_social', $filtros), fn ($d) => $this->mapRetPerc($d, '40')],
        ];
    }

    /** Aplica estado/fechas/cliente + búsqueda (serie/correlativo/número/cliente) en el servidor. */
    private function aplicarFiltros($query, ?string $campoCliente, array $filtros)
    {
        if ($estado = ($filtros['estado'] ?? null)) {
            $query->where('sunat_status', $estado);
        }
        if ($desde = ($filtros['desde'] ?? null)) {
            $query->whereDate('fecha_emision', '>=', $desde);
        }
        if ($hasta = ($filtros['hasta'] ?? null)) {
            $query->whereDate('fecha_emision', '<=', $hasta);
        }
        if (($cliente = ($filtros['cliente'] ?? null)) && $campoCliente) {
            $query->where($campoCliente, 'like', "%{$cliente}%");
        }

        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        if ($buscar !== '') {
            $texto = "%{$buscar}%";

            // Interpreta el número buscado, sin funciones SQL (portátil MySQL/SQLite):
            //  - "SERIE-CORRELATIVO" (ej. F001-00000123) → serie + correlativo
            //  - solo dígitos (ej. 00000123 o 123)        → correlativo (sin ceros a la izquierda)
            $serieBuscada = null;
            $correlativoBuscado = null;
            if (str_contains($buscar, '-')) {
                [$s, $c] = array_pad(explode('-', $buscar, 2), 2, '');
                $serieBuscada = trim($s) !== '' ? trim($s) : null;
                $c = ltrim(trim($c), '0');
                $correlativoBuscado = ctype_digit($c) ? (int) $c : null;
            } elseif (ctype_digit($buscar)) {
                $correlativoBuscado = (int) ltrim($buscar, '0');
            }

            $query->where(function ($q) use ($texto, $campoCliente, $serieBuscada, $correlativoBuscado) {
                $q->where('serie', 'like', $texto);
                if ($campoCliente) {
                    $q->orWhere($campoCliente, 'like', $texto);
                }
                if ($correlativoBuscado !== null) {
                    $q->orWhere(function ($w) use ($serieBuscada, $correlativoBuscado) {
                        $w->where('correlativo', $correlativoBuscado);
                        if ($serieBuscada !== null) {
                            $w->where('serie', 'like', "%{$serieBuscada}%");
                        }
                    });
                }
            });
        }

        return $query->orderByDesc('fecha_emision');
    }

    private function mapDoc(object $doc, string $tipoDoc): array
    {
        return [
            'id' => $doc->id,
            'tipo_doc' => $tipoDoc,
            'serie' => $doc->serie,
            'correlativo' => $doc->correlativo,
            'numero' => $doc->serie.'-'.str_pad((string) $doc->correlativo, 8, '0', STR_PAD_LEFT),
            'cliente' => $doc->client_razon_social,
            'fecha' => $doc->fecha_emision,
            'total' => (float) $doc->mto_imp_venta,
            'moneda' => $doc->tipo_moneda ?? 'PEN',
            'estado' => $doc->sunat_status ?? 'pendiente',
            // Solo facturas y notas (01/07/08) tienen el plazo de envío de 3 días; las
            // boletas (03) van por Resumen Diario, así que no se marcan aquí.
            'plazo_envio' => in_array($tipoDoc, ['01', '07', '08'], true) ? $this->estadoPlazo($doc) : null,
            'tiene_pdf' => ! empty($doc->pdf_path),
            'tiene_xml' => ! empty($doc->xml_path),
            'tiene_cdr' => ! empty($doc->cdr_path),
        ];
    }

    /**
     * Estado del comprobante frente al plazo de envío a SUNAT (R.S. 000003-2023):
     *  - 'por_vencer'    : aún no aceptado, el límite vence hoy o mañana.
     *  - 'vencido'       : aún no aceptado y el plazo YA pasó (hay que enviarlo ya).
     *  - 'fuera_de_plazo': aceptado pero ENVIADO después del plazo → extemporáneo,
     *                      sin validez tributaria aunque SUNAT lo haya recibido.
     *  - null            : al día, o estado que no aplica (anulado/rechazado).
     *
     * Espeja la lógica de sunat:plazo-envio. No usa columna nueva: lo deriva de
     * fecha_emision + sent_at + facturacion.plazo_envio_dias.
     */
    private function estadoPlazo(object $doc): ?string
    {
        if (empty($doc->fecha_emision)) {
            return null;
        }

        $estado = $doc->sunat_status ?? 'pendiente';
        $plazo = (int) config('facturacion.plazo_envio_dias', 3);
        $limite = \Illuminate\Support\Carbon::parse($doc->fecha_emision)->startOfDay()->addDays($plazo);

        if ($estado === 'aceptado') {
            // Extemporáneo: se aceptó pero la fecha de envío supera el plazo.
            return ! empty($doc->sent_at)
                && \Illuminate\Support\Carbon::parse($doc->sent_at)->startOfDay()->gt($limite)
                ? 'fuera_de_plazo'
                : null;
        }

        if (! in_array($estado, ['pendiente', 'enviado'], true)) {
            return null; // anulado / rechazado: no aplica
        }

        $dias = \Illuminate\Support\Carbon::today()->diffInDays($limite, false);

        return match (true) {
            $dias < 0 => 'vencido',
            $dias <= 1 => 'por_vencer',
            default => null,
        };
    }

    private function mapGuia(object $doc): array
    {
        return [
            'id' => $doc->id,
            'tipo_doc' => (string) ($doc->tipo_documento ?? '09'),
            'serie' => $doc->serie,
            'correlativo' => $doc->correlativo,
            'numero' => $doc->serie.'-'.str_pad((string) $doc->correlativo, 8, '0', STR_PAD_LEFT),
            'cliente' => $doc->destinatario_razon_social ?? '—',
            'fecha' => $doc->fecha_emision,
            'total' => 0.0,
            'moneda' => 'PEN',
            'estado' => $doc->sunat_status ?? 'pendiente',
            'tiene_pdf' => ! empty($doc->pdf_path),
            'tiene_xml' => ! empty($doc->xml_path),
            'tiene_cdr' => ! empty($doc->cdr_path),
        ];
    }

    private function mapRetPerc(object $doc, string $tipoDoc): array
    {
        return [
            'id' => $doc->id,
            'tipo_doc' => $tipoDoc,
            'serie' => $doc->serie,
            'correlativo' => $doc->correlativo,
            'numero' => $doc->serie.'-'.str_pad((string) $doc->correlativo, 8, '0', STR_PAD_LEFT),
            'cliente' => $tipoDoc === '20' ? ($doc->proveedor_razon_social ?? '—') : ($doc->cliente_razon_social ?? '—'),
            'fecha' => $doc->fecha_emision,
            'total' => (float) ($tipoDoc === '20' ? $doc->imp_retenido : $doc->imp_percibido),
            'moneda' => 'PEN',
            'estado' => $doc->sunat_status ?? 'pendiente',
            'tiene_pdf' => ! empty($doc->pdf_path),
            'tiene_xml' => ! empty($doc->xml_path),
            'tiene_cdr' => ! empty($doc->cdr_path),
        ];
    }
}
