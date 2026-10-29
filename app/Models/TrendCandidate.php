<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrendCandidate extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['nuevo', 'investigar', 'contactar_proveedor', 'muestra_solicitada', 'aprobado', 'descartado', 'comprado'];

    protected $casts = [
        'is_demo' => 'boolean',
        'est_buy_price' => 'decimal:2',
        'est_landed_extra_pct' => 'decimal:2',
        'est_sell_price' => 'decimal:2',
        'est_exchange_rate' => 'decimal:6',
        'score_breakdown' => 'array',
        'scored_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(TrendSnapshot::class)->latest('fetched_at');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(SupplierResearch::class)->latest('consulted_at');
    }
}
