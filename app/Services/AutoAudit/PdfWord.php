<?php

namespace App\Services\AutoAudit;

/**
 * Кусок текста из PDF вместе с местом, где он напечатан.
 *
 * `top` растёт вниз, как привычно человеку. В самом PDF ось направлена вверх, но нам
 * важен только порядок строк, поэтому знак переворачивается при разборе, а высота
 * страницы не нужна вовсе.
 */
class PdfWord
{
    public function __construct(
        public readonly float $left,
        public readonly float $top,
        public readonly string $text,
    ) {}
}
