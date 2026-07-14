<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Employee extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name',
        'position',
        'email',
        'phone',
        'employee_number',
        'birth_date',
        'hired_at',
        'fired_at',
        'employment_status',
        'password',
        'role_id',
        'status',
        'invite_token',
        'invite_sent_at',
        'invite_accepted_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'invite_token',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'hired_at' => 'date',
        'fired_at' => 'date',
        'invite_sent_at' => 'datetime',
        'invite_accepted_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Статусы аккаунта
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';

    public static array $statuses = [
        self::STATUS_PENDING => 'Ожидает подтверждения',
        self::STATUS_ACTIVE => 'Активен',
        self::STATUS_INACTIVE => 'Неактивен',
    ];

    public static array $statusColors = [
        self::STATUS_PENDING => 'yellow',
        self::STATUS_ACTIVE => 'green',
        self::STATUS_INACTIVE => 'red',
    ];

    // Статусы занятости
    const EMPLOYMENT_EMPLOYED = 'employed';
    const EMPLOYMENT_FIRED = 'fired';

    public static array $employmentStatuses = [
        self::EMPLOYMENT_EMPLOYED => 'Активный',
        self::EMPLOYMENT_FIRED => 'Уволен',
    ];

    // Связи
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'employee_module')
            ->withTimestamps();
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_employee')
            ->withTimestamps();
    }

    /** Клиенты, за которых этот сотрудник — ответственное лицо (одно на клиента). */
    public function responsibleClients(): HasMany
    {
        return $this->hasMany(Client::class, 'responsible_employee_id');
    }

    // Скоупы
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('full_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    public function scopeFilterByStatus($query, ?string $status)
    {
        if (!$status || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    // Методы
    public function isAdmin(): bool
    {
        return $this->role?->isAdmin() ?? false;
    }

    public function isHeadAccountant(): bool
    {
        return $this->role?->isHeadAccountant() ?? false;
    }

    public function isAccountant(): bool
    {
        return $this->role?->isAccountant() ?? false;
    }

    public function isAuditor(): bool
    {
        return $this->role?->isAuditor() ?? false;
    }

    public function isManager(): bool
    {
        return $this->role?->isManager() ?? false;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function generateInviteToken(): string
    {
        $this->invite_token = Str::random(64);
        $this->invite_sent_at = now();
        $this->save();

        return $this->invite_token;
    }

    public function acceptInvite(string $password): void
    {
        $this->password = $password;
        $this->status = self::STATUS_ACTIVE;
        $this->invite_token = null;
        $this->invite_accepted_at = now();
        $this->save();
    }

    public function deactivate(): void
    {
        $this->status = self::STATUS_INACTIVE;
        $this->save();
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->save();
    }

    public function hasAccessToModule(string $moduleName): bool
    {
        // Руководитель, как и админ, имеет полный доступ ко всем модулям
        if ($this->isAdmin() || $this->isManager()) {
            return true;
        }

        return $this->modules()->where('name', $moduleName)->exists();
    }

    public function isEmployed(): bool
    {
        return $this->employment_status === self::EMPLOYMENT_EMPLOYED;
    }

    public function isFired(): bool
    {
        return $this->employment_status === self::EMPLOYMENT_FIRED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::$statuses[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::$statusColors[$this->status] ?? 'gray';
    }

    public function getEmploymentStatusLabelAttribute(): string
    {
        return self::$employmentStatuses[$this->employment_status] ?? $this->employment_status;
    }
}
