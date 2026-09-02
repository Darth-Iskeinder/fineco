<?php

namespace Database\Seeders;

use App\Models\ClientStatus;
use Illuminate\Database\Seeder;

class ClientStatusSeeder extends Seeder
{
    /**
     * Фиксированный набор статусов клиента: Активен, Приостановлен, Завершен.
     *
     * `stops_tasks` — обслуживания по статусу нет, значит новых задач по смете
     * тоже нет. Стоит у обоих нерабочих статусов: для задач пауза и завершение
     * означают одно и то же. `closes_service` остаётся только у «Завершен» —
     * он отвечает за формулировки на экране и за импорт клиентов из файла.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Активен',       'color' => 'emerald', 'closes_service' => false, 'stops_tasks' => false, 'sort_order' => 1],
            ['name' => 'Приостановлен', 'color' => 'amber',   'closes_service' => false, 'stops_tasks' => true,  'sort_order' => 2],
            ['name' => 'Завершен',      'color' => 'slate',   'closes_service' => true,  'stops_tasks' => true,  'sort_order' => 3],
        ];

        foreach ($statuses as $status) {
            ClientStatus::updateOrCreate(['name' => $status['name']], $status);
        }
    }
}
