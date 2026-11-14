<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;

class BuscarClienteController extends Controller
{
    public function buscar(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $clientes = Client::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($q) {
                $query->where('numero_documento', 'like', "%{$q}%")
                    ->orWhere('razon_social', 'like', "%{$q}%");
            })
            ->orderBy('razon_social')
            ->limit(10)
            ->get(['id', 'tipo_documento', 'numero_documento', 'razon_social', 'direccion', 'email']);

        return response()->json($clientes);
    }

    public function buscarDocumento(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actualOFallar();
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        $facturas = Invoice::forTenant($tenant->id)
            ->where(function ($query) use ($q) {
                $query->where('serie', 'like', "%{$q}%")
                    ->orWhere('client_razon_social', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(serie, '-', LPAD(correlativo, 8, '0')) LIKE ?", ["%{$q}%"]);
            })
            ->whereIn('sunat_status', ['aceptado', 'pendiente', 'enviado'])
            ->orderByDesc('fecha_emision')
            ->limit(8)
            ->with('items')
            ->get(['id', 'serie', 'correlativo', 'client_tipo_doc', 'client_num_doc', 'client_razon_social', 'client_direccion', 'mto_imp_venta', 'tipo_moneda', 'fecha_emision'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'tipo_doc' => '01',
                'serie' => $d->serie,
                'correlativo' => $d->correlativo,
                'numero' => $d->serie.'-'.str_pad((string) $d->correlativo, 8, '0', STR_PAD_LEFT),
                'cliente' => $d->client_razon_social,
                'cliente_tipo_doc' => $d->client_tipo_doc,
                'cliente_num_doc' => $d->client_num_doc,
                'cliente_direccion' => $d->client_direccion,
                'total' => (float) $d->mto_imp_venta,
                'moneda' => $d->tipo_moneda,
                'fecha' => $d->fecha_emision,
                'items' => $this->mapItems($d->items),
            ]);

        $boletas = Boleta::forTenant($tenant->id)
            ->where(function ($query) use ($q) {
                $query->where('serie', 'like', "%{$q}%")
                    ->orWhere('client_razon_social', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(serie, '-', LPAD(correlativo, 8, '0')) LIKE ?", ["%{$q}%"]);
            })
            ->whereIn('sunat_status', ['aceptado', 'pendiente', 'enviado'])
            ->orderByDesc('fecha_emision')
            ->limit(8)
            ->with('items')
            ->get(['id', 'serie', 'correlativo', 'client_tipo_doc', 'client_num_doc', 'client_razon_social', 'client_direccion', 'mto_imp_venta', 'tipo_moneda', 'fecha_emision'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'tipo_doc' => '03',
                'serie' => $d->serie,
                'correlativo' => $d->correlativo,
                'numero' => $d->serie.'-'.str_pad((string) $d->correlativo, 8, '0', STR_PAD_LEFT),
                'cliente' => $d->client_razon_social,
                'cliente_tipo_doc' => $d->client_tipo_doc,
                'cliente_num_doc' => $d->client_num_doc,
                'cliente_direccion' => $d->client_direccion,
                'total' => (float) $d->mto_imp_venta,
                'moneda' => $d->tipo_moneda,
                'fecha' => $d->fecha_emision,
                'items' => $this->mapItems($d->items),
            ]);

        return response()->json($facturas->concat($boletas)->sortByDesc('fecha')->values());
    }

    /**
     * Normaliza los ítems de una factura/boleta a la forma que consume el editor
     * de notas (precio unitario CON IGV, más código y afectación para poder editar
     * cualquier campo como en la factura original).
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mapItems($items): array
    {
        return $items->map(fn ($it) => [
            'codigo' => $it->codigo ?? '',
            'cod_producto_sunat' => $it->cod_producto_sunat ?? '',
            'descripcion' => $it->descripcion,
            'cantidad' => (float) $it->cantidad,
            'precio_unitario' => (float) ($it->mto_precio_unitario ?? $it->mto_valor_unitario ?? 0),
            'unidad' => $it->unidad ?? 'NIU',
            'tip_afe_igv' => $it->tip_afe_igv ?? '10',
        ])->toArray();
    }
}
