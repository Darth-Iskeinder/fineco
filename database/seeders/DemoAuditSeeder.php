<?php

namespace Database\Seeders;

use App\Models\Audit;
use App\Models\AuditChecklistItem;
use App\Models\AuditChecklistTemplate;
use App\Models\AuditTaskReview;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Демо-данные для модуля «Аудит»: клиент с закрытыми БП за январь–апрель 2026,
 * бухгалтер, аудитор и один аудит в работе. Всё помечено «ДЕМО».
 * Идемпотентно — можно запускать повторно.
 *
 *   php artisan db:seed --class=AuditChecklistTemplateSeeder
 *   php artisan db:seed --class=DemoAuditSeeder
 *
 * Логины: auditor@fineco.kg / audit12345 (аудитор), buh@fineco.kg / buh12345 (бухгалтер)
 */
class DemoAuditSeeder extends Seeder
{
    private const PERIOD_START = '2026-01-01';
    private const PERIOD_END   = '2026-04-30';

    public function run(): void
    {
        $template = AuditChecklistTemplate::active()->orderBy('id')->first();
        if (!$template) {
            $this->command?->error('Сначала: php artisan db:seed --class=AuditChecklistTemplateSeeder');
            return;
        }

        $accountant = $this->employee('buh@fineco.kg', 'ДЕМО Марина Соколова', 'Бухгалтер', Role::ACCOUNTANT, ['buhtasks', 'clients']);
        $auditor    = $this->employee('auditor@fineco.kg', 'ДЕМО Айгерим Шерова', 'Аудитор', Role::EMPLOYEE, ['audit', 'clients']);

        $client = Client::firstOrCreate(
            ['name' => 'ДЕМО ОсОО Северный Ветер'],
            ['inn' => '00000000VETER', 'responsible_employee_id' => $accountant->id],
        );

        // БП с участками (services.service_group) — по ним группируются секции аудита
        $services = [
            ['ДЕМО · Разнесение банковской выписки', 'Банк',                    'Ежемесячно',    [1, 2, 3, 4]],
            ['ДЕМО · Отражение выручки ККМ',         'Касса / ККМ',             'Ежемесячно',    [1, 2, 3, 4]],
            ['ДЕМО · Авансовые отчёты',              'Расчёты с подотчётными',  'Ежемесячно',    [1, 2, 3, 4]],
            ['ДЕМО · Начисление зарплаты и НДФЛ',    'Зарплата и кадры',        'Ежемесячно',    [1, 2, 3, 4]],
            ['ДЕМО · Закрытие месяца',               'Финотчётность',           'Ежемесячно',    [1, 2, 3, 4]],
            ['ДЕМО · Единый налог за квартал',       'Налоги',                  'Ежеквартально', [3]],
            ['ДЕМО · Декларация НДС за квартал',     'НДС',                     'Ежеквартально', [3]],
        ];

        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);
        $created  = 0;

        foreach ($services as $i => [$name, $group, $periodicity, $months]) {
            $service = Service::firstOrCreate(
                ['name' => $name],
                ['periodicity' => $periodicity, 'cost' => 0, 'is_active' => true],
            );
            // service_group мог быть пустым, если БП создавался раньше — дозаполняем
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
                $due       = CarbonImmutable::create(2026, $month, 1)->endOfMonth()->addDays(15);
                $completed = $due->subDays(rand(1, 6))->setTime(rand(10, 18), rand(0, 59));

                $log = BuhTaskLog::firstOrCreate(
                    [
                        'client_id'        => $client->id,
                        'estimate_item_id' => $item->id,
                        'year'             => 2026,
                        'month'            => $month,
                    ],
                    [
                        'employee_id'      => $accountant->id,
                        'due_date'         => $due->toDateString(),
                        'status'           => 'completed',
                        'completed_at'     => $completed,
                        'reviewed_at'      => $completed->addHours(3),
                        'reviewed_by'      => $accountant->id,
                        'employee_comment' => $this->comment($group, $month),
                    ],
                );

                if ($log->wasRecentlyCreated) {
                    $created++;
                    $this->attachDemoDocument($log, $name, $month);
                }
            }
        }

        // Аудит в работе: чек-лист скопирован, часть работы уже сделана
        $audit = Audit::firstOrCreate(
            [
                'client_id'    => $client->id,
                'period_start' => self::PERIOD_START,
                'period_end'   => self::PERIOD_END,
            ],
            [
                'auditor_id'  => $auditor->id,
                'template_id' => $template->id,
                'status'      => Audit::STATUS_IN_PROGRESS,
            ],
        );

        if ($audit->checklistItems()->count() === 0) {
            $audit->copyChecklistFrom($template);
            $this->prefillChecklist($audit);
        }

        if ($audit->taskReviews()->count() === 0) {
            $this->prefillTaskReviews($audit, $auditor);
        }

        $this->command?->info("Демо-аудит готов: {$client->name}, {$audit->period_label}. Новых закрытых задач: {$created}.");
        $this->command?->info('Аудитор: auditor@fineco.kg / audit12345 · Бухгалтер: buh@fineco.kg / buh12345');
    }

    private function employee(string $email, string $name, string $position, string $roleName, array $modules): Employee
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $position]);

        $employee = Employee::firstOrCreate(
            ['email' => $email],
            [
                'full_name' => $name,
                'position'  => $position,
                'password'  => str_starts_with($email, 'auditor') ? 'audit12345' : 'buh12345',
                'role_id'   => $role->id,
                'status'    => Employee::STATUS_ACTIVE,
            ],
        );

        $employee->modules()->syncWithoutDetaching(
            Module::whereIn('name', $modules)->pluck('id')->all()
        );

        return $employee;
    }

    private function comment(string $group, int $month): ?string
    {
        return match (true) {
            $group === 'Банк' && $month === 2 => 'Платёж «ТехноПром» пока без договора — обещали прислать.',
            $group === 'Касса / ККМ' && $month === 3 => 'Расхождение по ККМ на 1 200 сом, разбираемся с клиентом.',
            $group === 'Зарплата и кадры' => 'Ведомость подписана, приказы приложены.',
            default => null,
        };
    }

    /** Кладёт на public-диск маленький файл, чтобы ссылка в аудите реально открывалась. */
    private function attachDemoDocument(BuhTaskLog $log, string $serviceName, int $month): void
    {
        $fileName = 'demo-' . $log->id . '.txt';
        $path     = 'buh_task_documents/' . $log->id . '/' . $fileName;

        Storage::disk('public')->put(
            $path,
            "ДЕМО-документ\n{$serviceName}\nМесяц: {$month}/2026\nЗадача #{$log->id}\n",
        );

        $log->documents()->save(new BuhTaskDocument([
            'path' => $path,
            'name' => 'Подтверждение_' . $month . '_2026.txt',
        ]));
    }

    /** Часть чек-листа уже заполнена — чтобы на экране было что смотреть. */
    private function prefillChecklist(Audit $audit): void
    {
        $items = $audit->checklistItems()->get();

        foreach ($items as $i => $item) {
            $data = match (true) {
                $i === 0 => ['status' => AuditChecklistItem::STATUS_OK,
                             'doc_link' => 'https://drive.google.com/demo/osv-01-04-2026.xlsx'],
                $i === 2 => ['status' => AuditChecklistItem::STATUS_ERROR,
                             'comment' => 'Выписка банка не сохранена в папке клиента. Подтвердить остатки из источника невозможно.'],
                $i === 6 => ['status' => AuditChecklistItem::STATUS_ERROR,
                             'doc_link' => 'https://drive.google.com/demo/kartochka-1210.xlsx',
                             'comment' => 'Остаток по 1210 не совпадает с выпиской на 340 000 сом.'],
                $i === 9 => ['status' => AuditChecklistItem::STATUS_ASK,
                             'comment' => 'Крупный остаток в кассе на 30.04 — нужны пояснения бухгалтера.'],
                $i === 11, $i === 12 => ['status' => AuditChecklistItem::STATUS_NA],
                $i < 8   => ['status' => AuditChecklistItem::STATUS_OK],
                default  => null,
            };

            if ($data) {
                $item->update($data);
            }
        }
    }

    /** Несколько вердиктов по задачам: и «норма», и замечания разных уровней. */
    private function prefillTaskReviews(Audit $audit, Employee $auditor): void
    {
        $logs = $audit->closedTaskLogs()->get();

        foreach ($logs->take(9) as $i => $log) {
            $isFinding = in_array($i, [3, 7], true);

            AuditTaskReview::create([
                'audit_id'        => $audit->id,
                'buh_task_log_id' => $log->id,
                'task_name'       => $log->estimateItem?->name ?? 'БП',
                'section'         => $log->estimateItem?->service?->service_group ?? 'Прочее',
                'verdict'         => $isFinding ? AuditTaskReview::VERDICT_FINDING : AuditTaskReview::VERDICT_OK,
                'severity'        => $isFinding ? ($i === 3 ? 'critical' : 'minor') : null,
                'comment'         => $isFinding
                    ? 'Платёж на 340 000 сом разнесён без договора и счёта в системе. Нет подтверждающего документа — риск при налоговой проверке.'
                    : null,
                'reviewed_by'     => $auditor->id,
                'reviewed_at'     => now(),
            ]);
        }
    }
}
