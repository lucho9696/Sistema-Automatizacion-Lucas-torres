<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotQuote extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'items' => 'array',
        'total' => 'decimal:2',
        'valid_until' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
