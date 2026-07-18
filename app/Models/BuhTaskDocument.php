<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BuhTaskDocument extends Model
{
    protected $fillable = ['path', 'name'];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
