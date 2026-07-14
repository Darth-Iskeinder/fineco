<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    // Имена ролей (roles.name)
    const ADMIN = 'admin';
    const EMPLOYEE = 'employee';
    const AUDITOR = 'auditor';
    const HEAD_ACCOUNTANT = 'head_accountant';
    const ACCOUNTANT = 'accountant';
    const MANAGER = 'manager';

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
        return $this->name === self::ADMIN;
    }

    public function isHeadAccountant(): bool
    {
        return $this->name === self::HEAD_ACCOUNTANT;
    }

    public function isAccountant(): bool
    {
        return $this->name === self::ACCOUNTANT;
    }

    public function isAuditor(): bool
    {
        return $this->name === self::AUDITOR;
    }

    public function isManager(): bool
    {
        return $this->name === self::MANAGER;
    }
}
