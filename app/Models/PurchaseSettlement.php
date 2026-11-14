<?php

namespace App\Models;

use App\Contracts\Documentable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Traits\HasPayments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Liquidación de compra electrónica (comprobante SUNAT tipo 04). El emisor (comprador) adquiere
 * bienes a un proveedor persona natural sin RUC y retiene IGV y renta. Documento UBL
 * {@code SelfBilledInvoice} 2.1 (ver {@see \App\Services\Greenter\Builders\LiquidacionCompraXmlBuilder}).
 */
class PurchaseSettlement extends Model implements Documentable
{
    use BelongsToTenant, HasFactory, HasPayments, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'sucursal_id', 'cod_local', 'serie', 'correlativo', 'fecha_emision',
        'tipo_operacion', 'tipo_moneda',
        'vendedor_tipo_doc', 'vendedor_num_doc', 'vendedor_nombre', 'vendedor_direccion',
        'vendedor_ubigeo', 'vendedor_tipo_domicilio',
        'lugar_direccion', 'lugar_ubigeo', 'lugar_tipo', 'productos_agropecuarios',
        'mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas', 'mto_igv', 'porcentaje_igv',
        'valor_venta', 'mto_base_renta', 'porcentaje_renta', 'mto_retencion_renta', 'sub_total', 'mto_imp_neto',
        'leyenda', 'observacion', 'guias', 'placa_vehiculo',
        'xml_path', 'cdr_path', 'pdf_path', 'hash_cpe',
        'sunat_status', 'sunat_code', 'sunat_description', 'sunat_notes', 'cdr_fecha',
        'payment_status', 'monto_pagado', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'cdr_fecha' => 'datetime',
            'sent_at' => 'datetime',
            'correlativo' => 'integer',
            'productos_agropecuarios' => 'boolean',
            'mto_oper_gravadas' => 'decimal:2',
            'mto_oper_exoneradas' => 'decimal:2',
            'mto_oper_inafectas' => 'decimal:2',
            'mto_igv' => 'decimal:2',
            'porcentaje_igv' => 'decimal:2',
            'valor_venta' => 'decimal:2',
            'mto_base_renta' => 'decimal:2',
            'porcentaje_renta' => 'decimal:2',
            'mto_retencion_renta' => 'decimal:2',
            'sub_total' => 'decimal:2',
            'mto_imp_neto' => 'decimal:2',
            'monto_pagado' => 'decimal:2',
            'guias' => 'array',
            'sunat_notes' => 'array',
        ];
    }

    public function getTipoDocumento(): string
    {
        return '04';
    }

    /** Serie-correlativo con 8 dígitos (el correlativo de la liquidación admite hasta 8). */
    public function getNumeroCompletoAttribute(): string
    {
        return $this->serie.'-'.str_pad((string) $this->correlativo, 8, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseSettlementItem::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
