<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Клиент прогона, которого нужно было проверять, или тревога по нему. См. AutoAuditWatch.
 */
class AutoAuditWatchClient extends Model
{
    use BelongsToTenant;

    public const NEW      = 'new';        // проверок ещё не было
    public const CODE     = 'code';       // поменялся код проверок
    public const SETTINGS = 'settings';   // настройки фирмы: БП, счета, выключенные проверки, старт
    public const CLIENT   = 'client';     // карточка: ИНН, метод учёта, что ведём
    public const TASKS    = 'tasks';      // задачи или файлы в отдельных месяцах
    public const SAME     = 'same';       // ничего не поменялось

    public const REASONS = [
        self::NEW      => 'новый',
        self::CODE     => 'код проверок',
        self::SETTINGS => 'настройки фирмы',
        self::CLIENT   => 'карточка клиента',
        self::TASKS    => 'задачи и файлы',
        self::SAME     => 'без изменений',
    ];

    protected $fillable = ['run_id', 'client_id', 'reason', 'months', 'checks', 'checks_by_month', 'changed', 'alarm', 'alarm_by_month'];

    protected $casts = [
        'months'         => 'array',
        'changed'        => 'array',
        'alarm'          => 'boolean',
        'alarm_by_month' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
