<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['parameters' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
