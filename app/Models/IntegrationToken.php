<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationToken extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime'];

    protected $hidden = ['access_token', 'refresh_token'];
}
