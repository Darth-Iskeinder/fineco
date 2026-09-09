<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use App\Services\AutoAudit\BalanceSheetReader;
use App\Services\AutoAudit\DocumentValue;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Пробное чтение ОСВ, приложенной к закрытой задаче.
 *
 * Первый шаг авто-аудита: ничего не сверяет и ничего не пишет в базу, только достаёт
 * число и показывает, откуда оно взято. Нужна, чтобы обкатать разбор выгрузки на живых
 * файлах прежде, чем на нём что-то строить.
 */
class ReadBalanceSheet extends Command
{
    protected $signature = 'autoaudit:read-osv
                            {--tenant= : Фирма (id)}
                            {--client= : Клиент (id или часть названия)}
                            {--period= : Период ГГГГ-ММ}
                            {--account=3210 : Номер счёта}
                            {--side=credit : Сторона оборота: credit или debit}';

    protected $description = 'Прочитать оборот по счёту из ОСВ, приложенной к задаче';

    public function handle(BalanceSheetReader $reader): int
    {
        [$year, $month] = $this->period();

        if (!$year) {
            return self::FAILURE;
        }

        return TenantContext::for((int) $this->option('tenant'), function () use ($reader, $year, $month) {
            $client = $this->client();

            if (!$client) {
                return self::FAILURE;
            }

            $service = Service::where('reference_id', 11)->first();

            if (!$service) {
                $this->error('В этой фирме нет БП с эталонным номером 11 (закрытие месяца и ОСВ).');

                return self::FAILURE;
            }

            $log = BuhTaskLog::where('client_id', $client->id)
                ->where('year', $year)->where('month', $month)
                ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
                ->with('documents')
                ->first();

            if (!$log) {
                $this->warn("Задачи по БП №11 за {$month}.{$year} у клиента «{$client->name}» нет.");

                return self::SUCCESS;
            }

            $this->line("Клиент:  {$client->name}");
            $this->line("Задача:  #{$log->id}, {$service->name}, {$month}.{$year}, статус {$log->status}");

            $document = $log->documents->first();

            if (!$document) {
                $this->warn('К задаче не приложен документ — сверять нечего.');

                return self::SUCCESS;
            }

            $this->line("Документ: {$document->name}");
            $this->newLine();

            $result = $reader->turnover(
                Storage::disk('local')->path($document->path),
                (string) $this->option('account'),
                (string) $this->option('side'),
            );

            $this->report($result);

            return self::SUCCESS;
        });
    }

    private function report(DocumentValue $result): void
    {
        match ($result->status) {
            DocumentValue::FOUND      => $this->info('Значение: ' . number_format($result->value, 2, ',', ' ')),
            DocumentValue::NOT_FOUND  => $this->warn('Не нашли: ' . $result->reason),
            DocumentValue::WRONG_DOC  => $this->error('Документ не тот: ' . $result->reason),
            DocumentValue::UNREADABLE => $this->error('Не прочитали: ' . $result->reason),
        };

        // str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются.
        $width = max(array_map('mb_strlen', array_keys($result->trace) ?: ['']));

        foreach ($result->trace as $key => $value) {
            $this->line('  ' . $key . str_repeat(' ', $width - mb_strlen($key) + 2) . $value);
        }
    }

    /** @return array{0: ?int, 1: ?int} */
    private function period(): array
    {
        if (!preg_match('/^(\d{4})-(\d{1,2})$/', (string) $this->option('period'), $m)) {
            $this->error('Период укажите как --period=2026-07');

            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function client(): ?Client
    {
        $needle = (string) $this->option('client');

        // scopeSearch, а не свой LIKE: на боевом PostgreSQL он регистрозависим,
        // а ilike, наоборот, не понимает MySQL, на котором идёт разработка.
        $client = ctype_digit($needle)
            ? Client::find((int) $needle)
            : Client::search($needle)->first();

        if (!$client) {
            $this->error('Клиент не найден: ' . $needle);
        }

        return $client;
    }
}
