<?php

namespace App\Services\AutoAudit;

use RuntimeException;
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
        try {
            $pages = (new Parser())->parseFile($path)->getPages();
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось разобрать PDF: ' . $this->firstLine($e->getMessage()));
        }

        $sheet = $pages[$page - 1] ?? null;

        if (!$sheet) {
            throw new RuntimeException("В файле нет страницы {$page}");
        }

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
