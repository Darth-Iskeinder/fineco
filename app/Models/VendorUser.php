<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Владелец системы. Живёт вне фирм: трейта BelongsToTenant здесь нет намеренно,
 * привязывать вендора к какой-то одной фирме не к чему.
 *
 * Заводится только командой `php artisan make:vendor` — из интерфейса добавить
 * себя в этот список нельзя.
 */
class VendorUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed'];
}
