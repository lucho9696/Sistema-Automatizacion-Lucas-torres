<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'attributes' => 'array',
        'list_price' => 'decimal:2',
        'avg_cost_base' => 'decimal:4',
        'is_active' => 'boolean',
        'stock_on_hand' => 'integer',
        'stock_reserved' => 'integer',
        'stock_held' => 'integer',
    ];

    protected $appends = ['stock_available'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function getStockAvailableAttribute(): int
    {
        return $this->stock_on_hand - $this->stock_reserved - $this->stock_held;
    }

    public function isSerialized(): bool
    {
        return (bool) $this->product->is_serialized;
    }
}
