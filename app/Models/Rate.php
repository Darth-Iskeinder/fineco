<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    protected $fillable = ['name', 'unit', 'price', 'conditions'];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
