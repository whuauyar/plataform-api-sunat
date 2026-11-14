<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para consultas calientes que aún no estaban cubiertas (E2 de la
 * auditoría). Las tablas ya tenían índices (tenant_id, sunat_status),
 * (tenant_id, fecha_emision), (tenant_id, client_num_doc) y
 * (tenant_id, created_at); esto completa los huecos de REPORTES:
 *
 *  - Reportes por sucursal / filtrados por local: (tenant_id, cod_local, fecha_emision)
 *  - Cuentas por cobrar: (tenant_id, payment_status, fecha_vencimiento)
 *  - Búsqueda de guías por destinatario: (tenant_id, destinatario_num_doc)
 *
 * Idempotente: cada índice se crea solo si la columna existe y el índice no.
 */
return new class extends Migration
{
    /**
     * Índices nuevos por tabla: nombre => columnas.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indices = [
        'invoices' => [
            'inv_tenant_local_fecha' => ['tenant_id', 'cod_local', 'fecha_emision'],
            'inv_tenant_pago_venc' => ['tenant_id', 'payment_status', 'fecha_vencimiento'],
        ],
        'boletas' => [
            'bol_tenant_local_fecha' => ['tenant_id', 'cod_local', 'fecha_emision'],
            'bol_tenant_pago_venc' => ['tenant_id', 'payment_status', 'fecha_vencimiento'],
        ],
        'dispatch_guides' => [
            'dg_tenant_dest_doc' => ['tenant_id', 'destinatario_num_doc'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indices as $tabla => $defs) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $defs): void {
                foreach ($defs as $nombre => $columnas) {
                    $faltaColumna = false;
                    foreach ($columnas as $col) {
                        if (! Schema::hasColumn($tabla, $col)) {
                            $faltaColumna = true;
                            break;
                        }
                    }

                    if ($faltaColumna || Schema::hasIndex($tabla, $nombre)) {
                        continue;
                    }

                    $table->index($columnas, $nombre);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indices as $tabla => $defs) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $defs): void {
                foreach (array_keys($defs) as $nombre) {
                    if (Schema::hasIndex($tabla, $nombre)) {
                        $table->dropIndex($nombre);
                    }
                }
            });
        }
    }
};
