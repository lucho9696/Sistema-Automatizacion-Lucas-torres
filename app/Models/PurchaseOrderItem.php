<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        'discount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_total_base' => 'decimal:2',
        'allocated_costs_base' => 'decimal:2',
        'landed_line_total_base' => 'decimal:2',
        'landed_unit_cost_base' => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
