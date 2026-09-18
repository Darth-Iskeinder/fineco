<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Service;
use App\Services\AutoAudit\BalanceSheetReader;
use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\SingleTaxReportReader;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Прогон читалок по всем документам эталонных БП — разведка, а не проверка.
 *
 * Ничего не сверяет и не пишет в базу: открывает каждый приложенный файл и говорит,
 * получилось его прочитать или нет. Нужна, чтобы узнать заранее, на скольких боевых
 * документах разбор работает, а не выяснять это, когда на нём уже что-то построено.
 *
 * Отдельно показывает разброс периодов: месяц задачи и период документа не совпадают
 * (июльский отчёт лежит на августовской задаче), и увидеть это лучше на всей истории.
 */
class ScanAutoAuditDocuments extends Command
{
    protected $signature = 'autoaudit:scan
                            {--tenant= : Фирма (id)}
                            {--ref=* : Эталонные номера БП; по умолчанию 3 и 11}
                            {--failures : Показать только те, что не прочитались}';

    protected $description = 'Прочитать все документы эталонных БП и показать сводку';

    /** Эталонный номер БП => чем его читать. */
    private const READERS = [
        3  => 'tax',
        11 => 'osv',
    ];

    public function handle(BalanceSheetReader $osv, SingleTaxReportReader $tax): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        $refs = array_map('intval', $this->option('ref') ?: array_keys(self::READERS));

        return TenantContext::for($tenant, function () use ($osv, $tax, $refs) {
            foreach ($refs as $ref) {
                $this->scanReference($ref, $osv, $tax);
            }

            return self::SUCCESS;
        });
    }

    private function scanReference(int $ref, BalanceSheetReader $osv, SingleTaxReportReader $tax): void
    {
        $service = Service::where('reference_id', $ref)->first();

        if (!$service) {
            $this->warn("В этой фирме нет БП с эталонным номером {$ref}.");

            return;
        }

        $this->newLine();
        $this->line("=== БП №{$ref}: {$service->name}");

        $logs = BuhTaskLog::whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->with(['client:id,name', 'documents'])
            ->orderBy('year')->orderBy('month')
            ->get();

        $counts = [];
        $shown  = 0;

        foreach ($logs as $log) {
            foreach ($log->documents as $document) {
                $result = $this->read($ref, $document->path, $osv, $tax);
                $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;

                if ($this->option('failures') && $result->isFound()) {
                    continue;
                }

                $shown++;
                $this->line(
                    '  ' . $this->mark($result)
                    . ' ' . $log->year . '-' . str_pad((string) $log->month, 2, '0', STR_PAD_LEFT)
                    . '  ' . $this->pad($log->client->name ?? '—', 30)
                    . $this->pad($result->period?->label() ?? '— период не разобран —', 25)
                    . ($result->isFound() ? number_format($result->value, 2, ',', ' ') : $result->reason)
                );
            }
        }

        $tasksWithoutDocuments = $logs->filter(fn ($l) => $l->documents->isEmpty())->count();

        $this->newLine();
        $this->line('  Задач всего: ' . $logs->count() . ', из них без документов: ' . $tasksWithoutDocuments);
        $this->line('  Документов: ' . array_sum($counts) . ($shown < array_sum($counts) ? " (показано {$shown})" : ''));

        foreach ($counts as $status => $count) {
            $this->line('    ' . $this->pad($this->statusLabel($status), 22) . $count);
        }
    }

    private function read(int $ref, string $path, BalanceSheetReader $osv, SingleTaxReportReader $tax): DocumentValue
    {
        $full = Storage::disk('local')->path($path);

        if (!is_readable($full)) {
            return DocumentValue::unreadable('файла нет на диске');
        }

        try {
            return self::READERS[$ref] === 'osv'
                ? $osv->turnover($full, '3210', 'credit')
                : $tax->taxableBase($full);
        } catch (Throwable $e) {
            // Разведка не должна падать на одном плохом файле: остальные важнее.
            return DocumentValue::unreadable(get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются. */
    private function pad(string $text, int $width): string
    {
        $text = mb_substr($text, 0, $width - 1);

        return $text . str_repeat(' ', max(1, $width - mb_strlen($text)));
    }

    private function mark(DocumentValue $result): string
    {
        return match ($result->status) {
            DocumentValue::FOUND     => '<fg=green>+</>',
            DocumentValue::NOT_FOUND => '<fg=yellow>?</>',
            default                  => '<fg=red>-</>',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            DocumentValue::FOUND      => 'прочитано',
            DocumentValue::NOT_FOUND  => 'нет такого счёта',
            DocumentValue::WRONG_DOC  => 'документ не тот',
            DocumentValue::UNREADABLE => 'не открылся',
            DocumentValue::SCAN       => 'скан или фото',
            DocumentValue::UNCERTAIN  => 'не удалось проверить',
            default                   => $status,
        };
    }
}
