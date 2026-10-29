<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentRecord extends Model
{
    protected $guarded = ['id'];

    public const CHANNELS = ['whatsapp', 'email', 'marketing'];

    public const METHODS = ['formulario_web', 'whatsapp_entrante', 'verbal_registrado', 'contrato', 'otro'];

    protected $casts = ['granted' => 'boolean', 'recorded_at' => 'datetime'];
}
