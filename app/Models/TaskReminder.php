<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Напоминание о сроке выполнения БП (выход воркера tasks:generate).
 * См. миграцию create_task_reminders_table — почему ключ по (employee, client, service, due_date).
 */
class TaskReminder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE    = 'done';

    protected $fillable = [
        'employee_id', 'client_id', 'service_id',
        'tax_office_code', 'branch_label',
        'name', 'periodicity', 'due_date',
        'status', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
