<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'needs_attention' => 'boolean',
        'handoff_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_message_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    protected $appends = ['window_open', 'window_expires_at'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(BotQuote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Ventana de atención de 24 h desde el último mensaje del cliente. */
    public function getWindowOpenAttribute(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }

    public function getWindowExpiresAtAttribute(): ?string
    {
        return $this->last_inbound_at?->copy()->addHours(24)->toIso8601String();
    }
}
