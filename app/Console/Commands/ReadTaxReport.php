<?php

namespace App\Console\Commands;

use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\SingleTaxReportReader;
use Illuminate\Console\Command;

/**
 * Пробное чтение отчёта по единому налогу.
 *
 * Ничего не сверяет и не пишет в базу: достаёт итоговую налогооблагаемую базу и
 * показывает, сошлась ли арифметика бланка. Нужна, чтобы обкатать разбор на живых
 * файлах прежде, чем на нём что-то строить.
 */
class ReadTaxReport extends Command
{
    protected $signature = 'autoaudit:read-tax {file* : Пути к PDF-отчётам}';

    protected $description = 'Прочитать итоговую налогооблагаемую базу из отчёта по единому налогу';

    public function handle(SingleTaxReportReader $reader): int
    {
        foreach ($this->argument('file') as $path) {
            $this->line(basename($path));

            if (!is_readable($path)) {
                $this->error('  Файл не открывается');

                continue;
            }

            $this->report($reader->taxableBase($path));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function report(DocumentValue $result): void
    {
        match ($result->status) {
            DocumentValue::FOUND      => $this->info('  База: ' . number_format($result->value, 2, ',', ' ')),
            DocumentValue::NOT_FOUND  => $this->warn('  Не нашли: ' . $result->reason),
            DocumentValue::WRONG_DOC  => $this->error('  Документ не тот: ' . $result->reason),
            DocumentValue::UNREADABLE => $this->error('  Не прочитали: ' . $result->reason),
            DocumentValue::SCAN       => $this->warn('  Скан или фото: ' . $result->reason),
            DocumentValue::UNCERTAIN  => $this->warn('  Не удалось проверить: ' . $result->reason),
            default                   => $this->error('  Неизвестный исход: ' . $result->status),
        };

        // str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются.
        $width = max(array_map('mb_strlen', array_keys($result->trace) ?: ['']));

        foreach ($result->trace as $key => $value) {
            $this->line('    ' . $key . str_repeat(' ', $width - mb_strlen($key) + 2) . $value);
        }
    }
}
