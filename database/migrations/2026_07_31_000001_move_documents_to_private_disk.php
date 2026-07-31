<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Переезд документов с публичного диска на приватный.
 *
 * До этой миграции файлы лежали в storage/app/public и раздавались nginx-ом через
 * симлинк public/storage — без авторизации, по угадываемому пути. Теперь тот же
 * относительный путь живёт в storage/app/private, а отдаёт файл DocumentController.
 *
 * Пути в БД НЕ меняются: колонка `path` хранит относительный путь вида
 * buh_task_documents/12/акт_1753.pdf, меняется только корень диска.
 *
 * Миграция идемпотентна: переносит файл за файлом, уже перенесённые пропускает,
 * поэтому безопасно запускать повторно после частичного сбоя.
 */
return new class extends Migration
{
    /** Каталоги документов на диске (одинаковые на public и private). */
    private const DIRS = [
        'clients',
        'buh_task_documents',
        'buh_adhoc_documents',
    ];

    public function up(): void
    {
        $this->moveTree(storage_path('app/public'), storage_path('app/private'));
    }

    public function down(): void
    {
        $this->moveTree(storage_path('app/private'), storage_path('app/public'));
    }

    /** Переносит каталоги документов из одного корня в другой. */
    private function moveTree(string $from, string $to): void
    {
        foreach (self::DIRS as $dir) {
            $source = $from . DIRECTORY_SEPARATOR . $dir;

            if (!is_dir($source)) {
                continue;
            }

            $this->moveDirectory($source, $to . DIRECTORY_SEPARATOR . $dir);
        }
    }

    private function moveDirectory(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new RuntimeException("Не удалось создать каталог {$target}");
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
            $targetPath = $target . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($sourcePath)) {
                $this->moveDirectory($sourcePath, $targetPath);
                continue;
            }

            // Файл уже на месте (повторный запуск) — исходник просто убираем.
            if (file_exists($targetPath)) {
                if (filesize($targetPath) === filesize($sourcePath)) {
                    unlink($sourcePath);
                    continue;
                }

                throw new RuntimeException(
                    "Файл {$targetPath} уже существует и отличается от исходного — разберитесь вручную"
                );
            }

            if (!rename($sourcePath, $targetPath)) {
                throw new RuntimeException("Не удалось перенести {$sourcePath} в {$targetPath}");
            }
        }

        // Пустой каталог-исходник больше не нужен; непустой оставляем как есть.
        @rmdir($source);
    }
};
