<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['en_transito', 'en_revision', 'disponible', 'reservado', 'vendido', 'devuelto', 'en_garantia', 'perdido'];

    protected $casts = ['unit_cost_base' => 'decimal:4'];

    /** IMEI/serie solo visible para roles autorizados (ver ProductUnitResource). */
    protected $hidden = [];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
