<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EnvioLogController extends Controller
{
    /**
     * Tipos del feed: [tabla, etiqueta]. Las etiquetas son constantes (no entran a SQL
     * desde el request), así que es seguro interpolarlas en el selectRaw.
     */
    private const TIPOS = [
        ['invoices', 'Factura'],
        ['boletas', 'Boleta'],
        ['credit_notes', 'Nota de crédito'],
        ['debit_notes', 'Nota de débito'],
    ];

    public function index(Request $request)
    {
        $estado = $request->string('estado')->toString();
        $buscar = trim($request->string('buscar')->toString());
        $hayBusqueda = $buscar !== '';

        // Feed en vivo (auto-refresh 10 s): por defecto acota a una ventana reciente para
        // no recorrer todo el histórico de todas las empresas en cada refresco. Una búsqueda
        // explícita levanta la ventana. Los filtros van DENTRO de cada rama (antes del UNION)
        // para que usen índices y la unión materializada sea mínima.
        $dias = (int) config('reportes.logs_envios_dias', 30);
        $desde = ($hayBusqueda || $dias <= 0) ? null : now()->subDays($dias)->startOfDay();

        $ramas = array_map(
            fn (array $t) => $this->rama($t[0], $t[1], $estado, $buscar, $desde),
            self::TIPOS,
        );

        $union = array_reduce(
            array_slice($ramas, 1),
            fn ($acc, $rama) => $acc->unionAll($rama),
            $ramas[0],
        );

        $logs = DB::query()->fromSub($union, 'logs')->select('*')
            ->orderByRaw('COALESCE(sent_at, fecha_emision) DESC')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/logs-envios', [
            'logs' => $logs,
            'filtros' => ['estado' => $estado, 'buscar' => $buscar],
        ]);
    }

    /**
     * Una rama del UNION con sus filtros ya aplicados sobre las columnas reales de la tabla.
     */
    private function rama(string $tabla, string $tipo, string $estado, string $buscar, ?\Carbon\CarbonInterface $desde): \Illuminate\Database\Query\Builder
    {
        $q = DB::table($tabla)
            ->selectRaw("'{$tipo}' AS tipo, {$tabla}.id, {$tabla}.tenant_id, {$tabla}.serie, {$tabla}.correlativo, {$tabla}.sunat_status AS estado, {$tabla}.sunat_code AS codigo, {$tabla}.sunat_description AS descripcion, {$tabla}.fecha_emision, {$tabla}.sent_at, {$tabla}.mto_imp_venta AS total, tenants.ruc, tenants.razon_social")
            ->join('tenants', 'tenants.id', '=', "{$tabla}.tenant_id")
            ->whereNull("{$tabla}.deleted_at");

        if ($estado !== '') {
            $q->where("{$tabla}.sunat_status", $estado);
        }

        if ($desde !== null) {
            $q->where("{$tabla}.fecha_emision", '>=', $desde);
        }

        if ($buscar !== '') {
            $term = "%{$buscar}%";
            $q->where(function ($w) use ($term, $tabla) {
                $w->where('tenants.ruc', 'like', $term)
                    ->orWhere('tenants.razon_social', 'like', $term)
                    ->orWhere("{$tabla}.serie", 'like', $term)
                    ->orWhere("{$tabla}.correlativo", 'like', $term)
                    ->orWhere("{$tabla}.sunat_description", 'like', $term);
            });
        }

        return $q;
    }
}
