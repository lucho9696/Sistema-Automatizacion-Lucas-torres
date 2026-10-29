<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'simulated' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
