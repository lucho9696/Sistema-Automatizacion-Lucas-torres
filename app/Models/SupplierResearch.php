<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierResearch extends Model
{
    protected $guarded = ['id'];

    protected $table = 'supplier_research';

    protected $casts = ['price' => 'decimal:2', 'consulted_at' => 'date:Y-m-d', 'verified' => 'boolean'];
}
