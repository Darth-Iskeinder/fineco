<?php

namespace Database\Seeders;

use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Role;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Данные по клиенту «ДЕМО ООО Альфа» для обкатки модуля «Аудит»:
 * смета из БП с участками и закрытые задачи за II квартал 2026 в разных состояниях
 * (с документами и без, с комментарием бухгалтера, с возвратами, принудительно закрытая).
 *
 * Специально добавлены задачи, которые в аудит попасть НЕ должны: незакрытые
 * внутри периода и закрытая за март — чтобы было видно, что отбор работает.
 * Сам аудит не создаётся: заводится с экрана «Аудит → Новый аудит».
 *
 *   php artisan db:seed --class=DemoAlfaAuditSeeder
 */
class DemoAlfaAuditSeeder extends Seeder
{
    /** БП: [название, участок (services.service_group), периодичность, месяцы] */
    private const SERVICES = [
        ['ДЕМО · Формирование ОСВ',              'ОСВ',                    'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Разнесение банковской выписки', 'Банк',                   'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Отражение выручки ККМ',         'Касса / ККМ',            'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Авансовые отчёты',              'Расчёты с подотчётными', 'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Реестр ЭСФ',                    'ЭСФ',                    'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Начисление зарплаты и НДФЛ',    'Зарплата и кадры',       'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Закрытие месяца',               'Финотчётность',          'Ежемесячно',    [4, 5, 6]],
        ['ДЕМО · Декларация НДС за квартал',     'НДС',                    'Ежеквартально', [6]],
    ];

    public function run(): void
    {
        $client = Client::firstOrCreate(
            ['name' => 'ДЕМО ООО Альфа'],
            ['inn' => '00000000ALFA'],
        );

        $accountant = Employee::firstOrCreate(
            ['email' => 'buh@fineco.kg'],
            [
                'full_name' => 'ДЕМО Марина Соколова',
                'position'  => 'Бухгалтер',
                'password'  => 'buh12345',
                'role_id'   => Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер'])->id,
                'status'    => Employee::STATUS_ACTIVE,
            ],
        );

        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);
        $created  = 0;

        foreach (self::SERVICES as $i => [$name, $group, $periodicity, $months]) {
            $service = Service::firstOrCreate(
                ['name' => $name],
                ['periodicity' => $periodicity, 'cost' => 0, 'is_active' => true],
            );
            $service->forceFill(['service_group' => $group])->save();

            $item = $estimate->items()->firstOrCreate(
                ['service_id' => $service->id, 'parent_id' => null],
                [
                    'type'        => 'recurring',
                    'name'        => $name,
                    'periodicity' => $periodicity,
                    'cost'        => 0,
                    'quantity'    => 1,
                    'total'       => 0,
                    'sort_order'  => $i,
                ],
            );

            foreach ($months as $month) {
                $created += $this->closedLog($client, $item, $accountant, $group, $month) ? 1 : 0;
            }
        }

        $this->outsidePeriodNoise($client, $estimate, $accountant);

        $this->command?->info("«{$client->name}»: закрытых задач за II квартал 2026 добавлено — {$created}.");
        $this->command?->info('Создайте аудит: Аудит → Новый аудит → ДЕМО ООО Альфа → период II квартал 2026.');
    }

    /** Закрытая задача с состоянием, зависящим от участка и месяца. */
    private function closedLog(Client $client, $item, Employee $accountant, string $group, int $month): bool
    {
        $due       = CarbonImmutable::create(2026, $month, 1)->endOfMonth()->addDays(15);
        $completed = $due->subDays(rand(1, 7))->setTime(rand(10, 18), rand(0, 59));

        // Разнообразие состояний: принудительное закрытие, возвраты, комментарии
        $forced = $group === 'Касса / ККМ' && $month === 5;
        $rework = match (true) {
            $group === 'Зарплата и кадры' && $month === 6 => 2,
            $group === 'Банк' && $month === 4             => 1,
            default                                       => 0,
        };

        $log = BuhTaskLog::firstOrCreate(
            [
                'client_id'        => $client->id,
                'estimate_item_id' => $item->id,
                'year'             => 2026,
                'month'            => $month,
            ],
            [
                'employee_id'         => $accountant->id,
                'due_date'            => $due->toDateString(),
                'status'              => 'completed',
                'completed_at'        => $completed,
                'reviewed_at'         => $forced ? null : $completed->addHours(2),
                'reviewed_by'         => $forced ? null : $accountant->id,
                'rework_count'        => $rework,
                'force_closed'        => $forced,
                'force_close_comment' => $forced ? 'Клиент не предоставил Z-отчёты, закрыто по решению главбуха.' : null,
                'employee_comment'    => $this->comment($group, $month),
            ],
        );

        if (!$log->wasRecentlyCreated) {
            return false;
        }

        // Документы есть не у всех — аудитору должно быть видно, где их не приложили
        $docs = match ($group) {
            'Банк', 'ОСВ'          => 2,
            'Касса / ККМ', 'НДС'   => 1,
            'Зарплата и кадры'     => $month === 6 ? 0 : 1,
            default                => 0,
        };

        for ($n = 1; $n <= $docs; $n++) {
            $this->attachDocument($log, $item->name, $month, $n);
        }

        return true;
    }

    private function comment(string $group, int $month): ?string
    {
        return match (true) {
            $group === 'Банк' && $month === 4 => 'Платёж «СтройМаркет» на 128 500 сом провела без договора — документ обещали дослать.',
            $group === 'Расчёты с подотчётными' && $month === 5 => 'По Иванову висит остаток с прошлого квартала, чеки не сданы.',
            $group === 'ЭСФ' && $month === 6 => 'Две входящие ЭСФ отклонены поставщиком, ждём перевыставления.',
            $group === 'Финотчётность' => 'Регламентные операции проведены, месяц закрыт.',
            default => null,
        };
    }

    private function attachDocument(BuhTaskLog $log, string $serviceName, int $month, int $n): void
    {
        $path = "buh_task_documents/{$log->id}/demo-{$log->id}-{$n}.txt";

        Storage::disk('local')->put(
            $path,
            "ДЕМО-документ\n{$serviceName}\nПериод: {$month}/2026\nЗадача #{$log->id}\n",
        );

        $log->documents()->save(new BuhTaskDocument([
            'path' => $path,
            'name' => sprintf('%s_%02d.2026_%d.txt', 'Подтверждение', $month, $n),
        ]));
    }

    /**
     * Шум вокруг периода: задачи, которых в аудите за II квартал быть не должно.
     * Закрытая за март и две незакрытые внутри квартала.
     */
    private function outsidePeriodNoise(Client $client, Estimate $estimate, Employee $accountant): void
    {
        $bank = $estimate->items()
            ->whereHas('service', fn ($q) => $q->where('service_group', 'Банк'))
            ->first();

        if (!$bank) {
            return;
        }

        BuhTaskLog::firstOrCreate(
            ['client_id' => $client->id, 'estimate_item_id' => $bank->id, 'year' => 2026, 'month' => 3],
            [
                'employee_id'  => $accountant->id,
                'due_date'     => '2026-04-15',
                'status'       => 'completed',
                'completed_at' => '2026-04-10 12:00:00',
            ],
        );

        $esf = $estimate->items()
            ->whereHas('service', fn ($q) => $q->where('service_group', 'ЭСФ'))
            ->first();

        foreach ([['in_progress', 4], ['review', 6]] as [$status, $month]) {
            if (!$esf) {
                break;
            }

            BuhTaskLog::firstOrCreate(
                ['client_id' => $client->id, 'estimate_item_id' => $esf->id, 'year' => 2025, 'month' => $month],
                [
                    'employee_id' => $accountant->id,
                    'due_date'    => CarbonImmutable::create(2025, $month, 20)->toDateString(),
                    'status'      => $status,
                    'started_at'  => CarbonImmutable::create(2025, $month, 18, 10)->toDateTimeString(),
                ],
            );
        }
    }
}
