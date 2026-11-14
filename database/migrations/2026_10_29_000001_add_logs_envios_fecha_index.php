<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El feed global "Logs de envíos" (admin) acota el UNION ALL de las 4 tablas por
     * `fecha_emision` sin filtrar por tenant. Los índices existentes son compuestos
     * (tenant_id, fecha_emision), que NO sirven para un rango global sin tenant_id, así
     * que ese rango degradaba a full scan. Este índice de una sola columna lo soporta.
     *
     * Aditivo y reversible; no toca datos. Idempotente (se puede re-ejecutar sin romper).
     */
    private array $tablas = ['invoices', 'boletas', 'credit_notes', 'debit_notes'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'fecha_emision')) {
                continue;
            }
            if ($this->tieneIndice($tabla, "{$tabla}_fecha_emision_index")) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->index('fecha_emision');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! $this->tieneIndice($tabla, "{$tabla}_fecha_emision_index")) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropIndex(['fecha_emision']);
            });
        }
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return collect(Schema::getIndexes($tabla))->contains(fn ($i) => ($i['name'] ?? null) === $indice);
    }
};
