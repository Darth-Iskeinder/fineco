<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientServiceSchedule;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Демо-данные для агенды «Сроки по клиентам». Всё помечено «ДЕМО» — легко найти и удалить.
 * Идемпотентно (firstOrCreate). Расписания подобраны под 2026-06-05, чтобы показать
 * Просрочено / Сегодня / На неделе / Позже и эффект индивидуального расписания.
 *
 *   php artisan db:seed --class=DemoTaskRemindersSeeder
 *   php artisan tasks:generate --date=2026-06-01 --horizon=45
 *
 * Демо-логин: demo@fineco.kg / demo12345
 */
class DemoTaskRemindersSeeder extends Seeder
{
    public function run(): void
    {
        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        Periodicity::firstOrCreate(['name' => 'Ежеквартально'], ['kind' => 'quarterly']);

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $employee = Employee::firstOrCreate(
            ['email' => 'demo@fineco.kg'],
            [
                'full_name' => 'Демо Бухгалтер',
                'position'  => 'Бухгалтер',
                'password'  => bcrypt('demo12345'),
                'role_id'   => $role->id,
                'status'    => Employee::STATUS_ACTIVE,
            ],
        );

        // Доступ к нужным модулям
        $employee->modules()->syncWithoutDetaching(
            Module::whereIn('name', ['buhtasks', 'clients'])->pluck('id')->all()
        );

        // Демо-БП с расписаниями
        $avans  = $this->service('ДЕМО · Авансовый отчёт',   'Ежемесячно',    [], [1]);
        $salary = $this->service('ДЕМО · Зарплатные налоги',  'Ежемесячно',    [], [5]);
        $osms   = $this->service('ДЕМО · Соцфонд (ОСМС)',     'Ежемесячно',    [], [10]);
        $nds    = $this->service('ДЕМО · Декларация НДС',     'Ежеквартально', [3, 6, 9, 12], [20]);

        // Клиент 1 — всё по дефолту (Просрочено/Сегодня/Неделя/Позже)
        $alpha = $this->client('ДЕМО ООО Альфа', '00000000ALFA', $employee->id);
        $this->estimateWith($alpha, [$avans, $salary, $osms, $nds]);

        // Клиент 2 — у НДС индивидуальный срок (ежемесячно 3-го вместо квартального 20-го)
        $bekov = $this->client('ДЕМО ИП Беков', '00000000BEKO', $employee->id);
        $this->estimateWith($bekov, [$salary, $nds]);
        ClientServiceSchedule::updateOrCreate(
            ['client_id' => $bekov->id, 'service_id' => $nds->id],
            ['periodicity' => 'Ежемесячно', 'start_month' => [], 'start_day' => [3]],
        );

        $this->command?->info('Демо создано. Логин: demo@fineco.kg / demo12345');
        $this->command?->info('Дальше: php artisan tasks:generate --date=2026-06-01 --horizon=45');
    }

    private function service(string $name, string $periodicity, array $months, array $days): Service
    {
        return Service::firstOrCreate(
            ['name' => $name],
            [
                'periodicity' => $periodicity,
                'start_month' => $months ?: null,
                'start_day'   => $days,
                'cost'        => 0,
                'is_active'   => true,
            ],
        );
    }

    private function client(string $name, string $inn, int $employeeId): Client
    {
        return Client::firstOrCreate(
            ['name' => $name],
            ['inn' => $inn, 'responsible_employee_id' => $employeeId],
        );
    }

    /** @param Service[] $services */
    private function estimateWith(Client $client, array $services): void
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        foreach ($services as $i => $svc) {
            $estimate->items()->firstOrCreate(
                ['service_id' => $svc->id, 'parent_id' => null],
                [
                    'type'        => 'recurring',
                    'name'        => $svc->name,
                    'periodicity' => $svc->periodicity,
                    'cost'        => 0,
                    'quantity'    => 1,
                    'total'       => 0,
                    'sort_order'  => $i,
                ],
            );
        }
    }
}
