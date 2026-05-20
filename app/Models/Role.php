<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function isAdmin(): bool
    {
        return $this->name === 'admin';
    }
}
