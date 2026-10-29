<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'exchange_rate_date' => 'date:Y-m-d',
        'sale_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'quote_valid_until' => 'date:Y-m-d',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'total_base' => 'decimal:2',
        'cost_total_base' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    protected $appends = ['balance_due'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getBalanceDueAttribute(): string
    {
        if (in_array($this->status, ['borrador', 'cancelada'], true)) {
            return '0.00';
        }

        return Money::sub($this->total, $this->paid_amount);
    }
}
