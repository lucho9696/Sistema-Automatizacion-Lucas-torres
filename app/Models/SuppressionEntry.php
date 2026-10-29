<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuppressionEntry extends Model
{
    protected $guarded = ['id'];

    protected $table = 'suppression_list';
}
