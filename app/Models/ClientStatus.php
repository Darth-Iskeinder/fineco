<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientStatus extends Model
{
    /**
     * Системные статусы. Справочник один на всех и из настроек не правится,
     * поэтому смысл этих трёх названий постоянен (см. ClientStatusSeeder).
     */
    public const NAME_ACTIVE = 'Активен';
    public const NAME_PAUSED = 'Приостановлен';
    public const NAME_CLOSED = 'Завершен';

    protected $fillable = [
        'name',
        'color',
        'closes_service',
        'stops_tasks',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Флаги читаем с подстраховкой по названию.
     *
     * На бою у «Завершен» флагов не было: их ставит сидер, а его там не
     * запускали. Статус на экране говорил «Завершен», а для системы был рабочим,
     * и смена статуса в карточке молча возвращала клиента в работу вместе с
     * задачами по смете. Ошибка в одной ячейке справочника не должна так дорого
     * стоить, поэтому системное название решает само, даже если флаг потеряли.
     * Колонку при этом не трогаем: чинит её команда clients:sync-service-status,
     * а tasks:generate предупреждает, пока она не починена.
     */
    protected function closesService(): Attribute
    {
        return Attribute::get(fn ($value) => (bool) $value || $this->nameIs(self::NAME_CLOSED));
    }

    protected function stopsTasks(): Attribute
    {
        return Attribute::get(fn ($value) => (bool) $value
            || $this->closes_service
            || $this->nameIs(self::NAME_PAUSED));
    }

    /** Флаги в базе расходятся с тем, что значит название статуса. */
    public function hasLostFlags(): bool
    {
        return $this->stops_tasks !== (bool) $this->getRawOriginal('stops_tasks')
            || $this->closes_service !== (bool) $this->getRawOriginal('closes_service');
    }

    /** Первый рабочий статус по порядку: его получает новый клиент. */
    public static function firstWorking(): ?self
    {
        return static::query()->orderBy('sort_order')->orderBy('id')->get()
            ->first(fn (self $status) => !$status->stops_tasks);
    }

    private function nameIs(string $name): bool
    {
        return mb_strtolower(trim((string) $this->getAttribute('name'))) === mb_strtolower($name);
    }
}
