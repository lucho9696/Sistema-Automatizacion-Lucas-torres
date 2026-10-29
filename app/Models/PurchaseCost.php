<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseCost extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'amount_base' => 'decimal:2'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
