<?php

namespace App\Services\AutoAudit;

use RuntimeException;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Текстовый слой PDF вместе с координатами каждого куска.
 *
 * Бланки налоговой приходят так: сама форма — фоновая картинка, а поверх неё настоящим
 * текстом напечатаны только заполненные числа. Подписей полей и их номеров в тексте нет
 * вовсе, поэтому зацепиться, как в Excel, за название строки нельзя — остаются координаты.
 *
 * Разбираем библиотекой на чистом PHP, а не системным pdftotext: на боевом сервере его
 * нет, а ставить пакет ради этого пришлось бы с правами root. Заодно вышло вдвое быстрее
 * (нет запуска процесса) и удобнее: число приезжает целиком, а не разбитым по разрядам.
 */
class PdfTextLayer
{
    /**
     * Куски текста первой страницы. Бланк отчёта умещается на неё целиком, вторая пустая.
     *
     * @return PdfWord[]
     * @throws RuntimeException если файл не разобрался
     */
    public function words(string $path, int $page = 1): array
    {
        $sheet = $this->parse($path)[$page - 1] ?? null;

        if (!$sheet) {
            throw new RuntimeException("В файле нет страницы {$page}");
        }

        return $this->wordsOf($sheet);
    }

    /**
     * Куски текста всех страниц за один разбор файла. Ключ: номер страницы, с единицы.
     *
     * Нужно формам, где итог на первой странице, а расшифровка на следующих: разбирать
     * файл заново ради каждой страницы вышло бы в разы дольше.
     *
     * @return array<int, PdfWord[]>
     * @throws RuntimeException если файл не разобрался
     */
    public function pages(string $path): array
    {
        $result = [];

        foreach ($this->parse($path) as $i => $sheet) {
            $result[$i + 1] = $this->wordsOf($sheet);
        }

        return $result;
    }

    /** @return Page[] */
    private function parse(string $path): array
    {
        try {
            return array_values((new Parser())->parseFile($path)->getPages());
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось разобрать PDF: ' . $this->firstLine($e->getMessage()));
        }
    }

    /** @return PdfWord[] */
    private function wordsOf(Page $sheet): array
    {
        $words = [];

        // getDataTm отдаёт матрицу размещения и текст: нас интересуют сдвиги по x и y.
        foreach ($sheet->getDataTm() as [$matrix, $text]) {
            if (trim($text) === '') {
                continue;
            }

            $words[] = new PdfWord((float) $matrix[4], -(float) $matrix[5], $text);
        }

        return $words;
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}
