<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['nuevo', 'por_verificar', 'calificado', 'contactado', 'respondio', 'sin_respuesta', 'interesado', 'cotizacion_enviada', 'negociacion', 'cliente', 'sin_interes', 'descartado', 'no_contactar'];

    protected $casts = [
        'captured_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'erased_at' => 'datetime',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class)->latest('recorded_at');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_of_id');
    }
}
