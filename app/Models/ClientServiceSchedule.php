<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Индивидуальное расписание БП у клиента. Строка целиком перекрывает дефолтное
 * расписание services для пары (client_id, service_id). Резолв эффективного
 * расписания и расчёт дат — в Service::dueDatesForClient() / resolveScheduleRaw().
 */
class ClientServiceSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_id',
        'service_id',
        'periodicity',
        'start_month',
        'start_day',
    ];

    protected $casts = [
        'start_month' => 'array',
        'start_day'   => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
