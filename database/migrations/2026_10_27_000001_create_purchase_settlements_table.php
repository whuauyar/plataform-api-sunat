<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquidación de compra electrónica (comprobante SUNAT tipo 04): módulo nuevo.
 * Estructura según el Anexo N.° 9-A (literal e) y el Anexo N.° 27 de la RS 097-2012/SUNAT
 * (incorporados por la RS 244-2019/SUNAT). El emisor (comprador) compra a un proveedor
 * persona natural sin RUC (DNI o carné de extranjería) y retiene IGV y renta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('cod_local', 4)->default('0000');
            $table->string('serie', 4);
            $table->unsignedInteger('correlativo');
            $table->dateTime('fecha_emision');
            $table->string('tipo_operacion', 4)->default('0501');
            $table->string('tipo_moneda', 3)->default('PEN');

            // Vendedor (proveedor persona natural sin RUC): DNI o carné de extranjería
            $table->string('vendedor_tipo_doc', 1);
            $table->string('vendedor_num_doc', 15);
            $table->string('vendedor_nombre', 1500);
            $table->string('vendedor_direccion', 250);
            $table->string('vendedor_ubigeo', 6);
            $table->string('vendedor_tipo_domicilio', 2); // catálogo 60

            // Lugar de la operación
            $table->string('lugar_direccion', 250);
            $table->string('lugar_ubigeo', 6);
            $table->string('lugar_tipo', 2); // catálogo 60

            $table->boolean('productos_agropecuarios')->default(false);

            // Montos
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('porcentaje_igv', 5, 2)->default(18);
            $table->decimal('valor_venta', 12, 2)->default(0);
            $table->decimal('mto_base_renta', 12, 2)->default(0);
            $table->decimal('porcentaje_renta', 5, 2)->default(0);
            $table->decimal('mto_retencion_renta', 12, 2)->default(0);
            $table->decimal('sub_total', 12, 2)->default(0);   // TaxInclusiveAmount
            $table->decimal('mto_imp_neto', 12, 2)->default(0); // PayableAmount: neto a pagar al vendedor

            $table->string('leyenda', 500)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->json('guias')->nullable();
            $table->string('placa_vehiculo', 8)->nullable();

            // Archivos y estado SUNAT (sunat_status como string para admitir estados de reversión)
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('hash_cpe')->nullable();
            $table->string('sunat_status', 30)->default('pendiente');
            $table->string('sunat_code', 20)->nullable();
            $table->text('sunat_description')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->dateTime('cdr_fecha')->nullable(); // recepción del CDR aceptado: base del plazo de reversión

            // Pagos
            $table->string('payment_status', 20)->default('pendiente');
            $table->decimal('monto_pagado', 12, 2)->default(0);

            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'serie', 'correlativo']);
            $table->index(['tenant_id', 'sunat_status']);
            $table->index(['tenant_id', 'fecha_emision']);
            $table->index(['tenant_id', 'vendedor_num_doc']);
        });

        Schema::create('purchase_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_settlement_id')->constrained('purchase_settlements')->cascadeOnDelete();
            $table->string('codigo', 30)->nullable();
            $table->string('cod_producto_sunat', 8);
            $table->string('descripcion', 500);
            $table->string('unidad', 3);
            $table->decimal('cantidad', 22, 10);
            $table->decimal('valor_unitario', 22, 10);
            $table->decimal('precio_unitario', 22, 10);
            $table->string('tip_afe_igv', 2);
            $table->decimal('valor_venta', 12, 2);
            $table->decimal('porcentaje_igv', 5, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('porcentaje_renta', 5, 2);
            $table->decimal('renta', 12, 2);
            $table->json('oro')->nullable(); // tipo de operación 0503 (catálogo 55)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_settlement_items');
        Schema::dropIfExists('purchase_settlements');
    }
};
