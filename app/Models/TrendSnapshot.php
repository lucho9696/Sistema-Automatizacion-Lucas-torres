<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrendSnapshot extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_demo' => 'boolean', 'fetched_at' => 'datetime'];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(TrendCandidate::class, 'trend_candidate_id');
    }
}
