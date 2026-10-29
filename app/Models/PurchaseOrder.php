<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'exchange_rate_date' => 'date:Y-m-d',
        'exchange_rate' => 'decimal:6',
        'goods_total' => 'decimal:2',
        'goods_total_base' => 'decimal:2',
        'extra_costs_base' => 'decimal:2',
        'landed_total_base' => 'decimal:2',
        'allocation_snapshot' => 'array',
        'received_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(PurchaseCost::class);
    }
}
