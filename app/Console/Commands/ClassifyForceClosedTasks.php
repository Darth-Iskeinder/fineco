<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Service;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Разметка старых принудительных закрытий: причина из списка по тексту комментария.
 *
 * До 28.09.2026 причина была только свободным текстом. Автоаудит читает выбор из списка
 * (BuhTaskLog::FORCE_REASONS), поэтому старым задачам его надо проставить один раз.
 * Берём только задачи эталонных БП автоаудита: только там выбор что-то меняет, а ошибка
 * разбора в прочих БП дала бы в списках неверное слово перед комментарием.
 *
 * По умолчанию только показывает, что предлагает. Пишет с --apply, и только туда, где
 * причина ещё пустая: выбор, сделанный человеком, не перетираем. Повторный запуск безопасен.
 * Что не разобралось, остаётся пустым и для автоаудита значит «не знаем», как раньше.
 */
class ClassifyForceClosedTasks extends Command
{
    protected $signature = 'buhtasks:classify-force-closed
        {--tenant= : Фирма (id)}
        {--apply : Записать предложенные причины}';

    protected $description = 'Проставить причину старым принудительным закрытиям по тексту комментария';

    /**
     * Порядок важен: первое совпадение побеждает. «Освобождён» и «квартал» раньше нуля,
     * потому что «ежеквартально, нулевой не сдаёт» это квартал, а не ноль.
     */
    private const RULES = [
        BuhTaskLog::FORCE_EXEMPT    => '/освобожд/u',
        BuhTaskLog::FORCE_QUARTERLY => '/квартал/u',
        BuhTaskLog::FORCE_ZERO      => '/нул|нет\s+(операц|движен|выручк|продаж|сотрудник|начислен)|не\s+было|начислени\S*.*\sнет\b/u',
    ];

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        return TenantContext::for($tenant, fn () => $this->classify());
    }

    /** Причина по тексту комментария или null, если текст ни на что не похож. */
    public static function guess(?string $comment): ?string
    {
        $text = mb_strtolower(trim((string) $comment));

        foreach (self::RULES as $reason => $pattern) {
            if (preg_match($pattern, $text)) {
                return $reason;
            }
        }

        return null;
    }

    private function classify(): int
    {
        $services = Service::whereIn('reference_id', [
            AutoAuditRunner::REF_TAX_REPORT, AutoAuditRunner::REF_FORM_161, AutoAuditRunner::REF_BALANCE_SHEET,
        ])->pluck('name', 'id');

        $logs = BuhTaskLog::where('force_closed', true)
            ->whereNull('force_close_reason')
            ->whereHas('estimateItem', fn ($q) => $q->whereIn('service_id', $services->keys()))
            ->with(['client:id,name', 'estimateItem:id,service_id'])
            ->orderBy('year')->orderBy('month')->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            $this->info('Неразмеченных принудительных закрытий нет.');

            return self::SUCCESS;
        }

        $rows    = [];
        $planned = [];

        foreach ($logs as $log) {
            $reason = self::guess($log->force_close_comment);

            if ($reason) {
                $planned[$reason][] = $log->id;
            }

            $rows[] = [
                $log->id,
                $log->client?->name ?? '—',
                $services[$log->estimateItem?->service_id] ?? '—',
                sprintf('%02d.%04d', $log->month, $log->year),
                trim((string) $log->force_close_comment),
                $reason ? BuhTaskLog::FORCE_REASONS[$reason][1] : '— оставить пустым',
            ];
        }

        $this->table(['Задача', 'Клиент', 'БП', 'Месяц', 'Комментарий', 'Причина'], $rows);

        foreach (BuhTaskLog::FORCE_REASONS as $reason => [, $label]) {
            if (isset($planned[$reason])) {
                $this->line("{$label}: " . count($planned[$reason]));
            }
        }

        $this->line('Оставить пустым: ' . ($logs->count() - array_sum(array_map('count', $planned))));

        if (!$this->option('apply')) {
            $this->newLine();
            $this->line('Ничего не записано. Чтобы записать, запустите с --apply');

            return self::SUCCESS;
        }

        $written = 0;

        foreach ($planned as $reason => $ids) {
            // whereNull ещё раз: между показом и записью человек мог выбрать причину сам.
            $written += BuhTaskLog::whereIn('id', $ids)
                ->whereNull('force_close_reason')
                ->update(['force_close_reason' => $reason]);
        }

        $this->newLine();
        $this->info("Записано: {$written}");

        return self::SUCCESS;
    }
}
