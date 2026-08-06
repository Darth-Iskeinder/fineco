<?php

namespace App\Services;

/**
 * Читает загруженный CSV в строки вида «ключ колонки => значение».
 *
 * Снисходителен к тому, как файл прошёл через чужой Excel: разделитель может
 * оказаться запятой, кодировка — с BOM или без, заголовки — в другом регистре
 * и с лишними пробелами, колонки — в другом порядке или с посторонними
 * добавками. Всё это не повод отказывать человеку в импорте.
 *
 * Строг ровно в одном: без колонок «Название» и «ИНН» файл бессмыслен.
 */
final class ClientCsvParser
{
    /** Больше строк за раз не берём: это ручной перенос базы, а не поток данных. */
    public const MAX_ROWS = 1000;

    public function __construct(
        /** @var array<int, string> Ключи колонок в порядке файла; null — колонка нам незнакома */
        private array $map = [],
    ) {
    }

    /**
     * @return array{rows: array<int, array{line: int, values: array<string, string>}>, error: ?string}
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return ['rows' => [], 'error' => 'Не удалось прочитать файл.'];
        }

        try {
            $delimiter = $this->sniffDelimiter($path);
            $header    = fgetcsv($handle, 0, $delimiter, '"', '');

            if (!$header) {
                return ['rows' => [], 'error' => 'Файл пуст.'];
            }

            $this->map = $this->mapHeader($header);

            foreach (['name', 'inn'] as $required) {
                if (!in_array($required, $this->map, true)) {
                    return ['rows' => [], 'error' => 'В файле нет колонки «' . ClientCsvSchema::COLUMNS[$required] . '». Скачайте шаблон и заполните его.'];
                }
            }

            $rows = [];
            $line = 1;

            while (($raw = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                // Пустая строка в конце файла — обычное дело для Excel, молчим.
                if ($this->isBlank($raw)) {
                    continue;
                }

                $rows[] = ['line' => $line, 'values' => $this->mapRow($raw)];

                if (count($rows) > self::MAX_ROWS) {
                    return ['rows' => [], 'error' => 'В файле больше ' . self::MAX_ROWS . ' строк. Разбейте его на части.'];
                }
            }

            return ['rows' => $rows, 'error' => null];
        } finally {
            fclose($handle);
        }
    }

    /** Чем разделены поля: наш «;» или запятая из чужой выгрузки. */
    private function sniffDelimiter(string $path): string
    {
        $first = fgets(fopen($path, 'r')) ?: '';

        return substr_count($first, ',') > substr_count($first, ';') ? ',' : ';';
    }

    /** @return array<int, ?string> позиция в файле => ключ нашей колонки */
    private function mapHeader(array $header): array
    {
        $known = [];

        foreach (ClientCsvSchema::COLUMNS as $key => $title) {
            $known[$this->normalize($title)] = $key;
        }

        $map = [];

        foreach ($header as $title) {
            $map[] = $known[$this->normalize($title)] ?? null;
        }

        return $map;
    }

    /** @return array<string, string> */
    private function mapRow(array $raw): array
    {
        $values = [];

        foreach ($this->map as $position => $key) {
            if ($key === null) {
                continue;
            }

            $values[$key] = trim((string) ($raw[$position] ?? ''));
        }

        return $values;
    }

    private function isBlank(array $raw): bool
    {
        foreach ($raw as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** BOM, регистр, неразрывные пробелы — всё, чем отличаются одинаковые по смыслу заголовки. */
    private function normalize(string $value): string
    {
        $value = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ['', ' '], $value);

        return mb_strtolower(trim($value));
    }
}
