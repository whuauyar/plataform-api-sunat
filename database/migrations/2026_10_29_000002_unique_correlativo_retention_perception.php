<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retención (R) y percepción (P) tenían un índice NO único sobre
     * (tenant_id, serie, correlativo), a diferencia de facturas/boletas que sí usan
     * UNIQUE. Sin el UNIQUE, una condición de carrera podía persistir dos comprobantes
     * con el mismo número (SUNAT los rechazaría, pero la BD quedaba inconsistente).
     *
     * Antes de imponer el UNIQUE se verifica que no haya duplicados; si los hubiera, se
     * aborta con un mensaje accionable (no destructivo) para que el operador los resuelva
     * y vuelva a migrar, en vez de romper el deploy a medias. Reversible.
     */
    private array $tablas = ['retentions', 'perceptions'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $duplicados = DB::table($tabla)
                ->selectRaw('tenant_id, serie, correlativo, COUNT(*) AS c')
                ->groupBy('tenant_id', 'serie', 'correlativo')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($duplicados->isNotEmpty()) {
                $ej = $duplicados->take(3)->map(
                    fn ($d) => "tenant {$d->tenant_id} · {$d->serie}-{$d->correlativo} (×{$d->c})"
                )->implode('; ');

                throw new \RuntimeException(
                    "No se puede imponer el UNIQUE en {$tabla}: hay {$duplicados->count()} ".
                    "número(s) duplicado(s). Resuélvelos y vuelve a ejecutar la migración. Ejemplos: {$ej}."
                );
            }

            if ($this->tieneIndice($tabla, "{$tabla}_tenant_id_serie_correlativo_unique")) {
                continue; // ya aplicado
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if ($this->tieneIndice($tabla, "{$tabla}_tenant_id_serie_correlativo_index")) {
                    $table->dropIndex("{$tabla}_tenant_id_serie_correlativo_index");
                }
                $table->unique(['tenant_id', 'serie', 'correlativo']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! $this->tieneIndice($tabla, "{$tabla}_tenant_id_serie_correlativo_unique")) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'serie', 'correlativo']);
                $table->index(['tenant_id', 'serie', 'correlativo']);
            });
        }
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return collect(Schema::getIndexes($tabla))->contains(fn ($i) => ($i['name'] ?? null) === $indice);
    }
};
