<?php

namespace Database\Seeders;

use App\Models\ClientStatus;
use Illuminate\Database\Seeder;

class ClientStatusSeeder extends Seeder
{
    /**
     * Фиксированный набор статусов клиента: Активен, Приостановлен, Завершен.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Активен',       'color' => 'emerald', 'closes_service' => false, 'sort_order' => 1],
            ['name' => 'Приостановлен', 'color' => 'amber',   'closes_service' => false, 'sort_order' => 2],
            ['name' => 'Завершен',      'color' => 'slate',   'closes_service' => true,  'sort_order' => 3],
        ];

        foreach ($statuses as $status) {
            ClientStatus::updateOrCreate(['name' => $status['name']], $status);
        }
    }
}
