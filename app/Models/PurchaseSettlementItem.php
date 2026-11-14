<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem de una liquidación de compra (tabla purchase_settlement_items). */
class PurchaseSettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_settlement_id', 'codigo', 'cod_producto_sunat', 'descripcion', 'unidad',
        'cantidad', 'valor_unitario', 'precio_unitario', 'tip_afe_igv',
        'valor_venta', 'porcentaje_igv', 'igv', 'porcentaje_renta', 'renta', 'oro',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:10',
            'valor_unitario' => 'decimal:10',
            'precio_unitario' => 'decimal:10',
            'valor_venta' => 'decimal:2',
            'porcentaje_igv' => 'decimal:2',
            'igv' => 'decimal:2',
            'porcentaje_renta' => 'decimal:2',
            'renta' => 'decimal:2',
            'oro' => 'array',
        ];
    }

    public function purchaseSettlement(): BelongsTo
    {
        return $this->belongsTo(PurchaseSettlement::class);
    }
}
