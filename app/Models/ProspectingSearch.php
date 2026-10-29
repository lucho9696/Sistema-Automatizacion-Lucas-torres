<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProspectingSearch extends Model
{
    public const FREQUENCIES = [7, 14, 30];

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_preview' => 'array',
    ];

    public function textQuery(): string
    {
        return trim("{$this->query} en {$this->city}".($this->province ? ", {$this->province}" : ''));
    }
}
