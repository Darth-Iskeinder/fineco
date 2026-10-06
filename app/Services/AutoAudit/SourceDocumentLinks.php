<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditResult;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use Illuminate\Support\Collection;

/**
 * Ссылки на файлы из снимка проверки.
 *
 * Строка результата помнит document_id файла, который читала проверка. После «Исправил»
 * старый файл удаляется, а строка живёт до следующего прогона, и в истории остаётся
 * навсегда. Ссылка на удалённый документ давала 404. Снимок не переписываем: он честно
 * говорит, что читала проверка. Вместо мёртвой ссылки показываем текущие файлы задачи
 * с пометкой «заменён».
 *
 * Всё, что нужно странице, берётся двумя запросами на весь список строк, а не по
 * запросу на каждый источник.
 */
class SourceDocumentLinks
{
    /**
     * @param array<int, true>                    $alive   id документов снимка, которые на месте
     * @param Collection<int, Collection>         $current документы задач с заменённым файлом, по log_id
     */
    private function __construct(private array $alive, private Collection $current)
    {
    }

    /** @param iterable<AutoAuditResult> $results */
    public static function for(iterable $results): self
    {
        $sources = collect($results)->flatMap(fn (AutoAuditResult $r) => $r->sources ?? []);

        $ids = $sources->pluck('document_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return new self([], collect());
        }

        $alive = array_fill_keys(BuhTaskDocument::whereIn('id', $ids)->pluck('id')->all(), true);

        $replacedLogIds = $sources
            ->filter(fn (array $s) => !empty($s['document_id']) && !isset($alive[(int) $s['document_id']]))
            ->pluck('log_id')->filter()->unique()->values();

        $current = $replacedLogIds->isEmpty() ? collect() : BuhTaskDocument::query()
            ->where('documentable_type', (new BuhTaskLog())->getMorphClass())
            ->whereIn('documentable_id', $replacedLogIds)
            ->orderBy('id')
            ->get(['id', 'documentable_id', 'name'])
            ->groupBy('documentable_id');

        return new self($alive, $current);
    }

    /** Файл из снимка удалён: его заменили или убрали из задачи. */
    public function replaced(array $source): bool
    {
        return !empty($source['document_id']) && !isset($this->alive[(int) $source['document_id']]);
    }

    /** Ссылка на файл снимка, пока он на месте. */
    public function url(array $source): ?string
    {
        return empty($source['document_id']) || $this->replaced($source)
            ? null
            : route('documents.task', $source['document_id']);
    }

    /**
     * Что лежит в задаче сейчас, вместо заменённого файла. Пусто, если файл не заменён
     * или в задаче больше ничего нет.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public function current(array $source): array
    {
        if (!$this->replaced($source)) {
            return [];
        }

        return ($this->current[(int) ($source['log_id'] ?? 0)] ?? collect())
            ->map(fn (BuhTaskDocument $d) => ['name' => $d->name, 'url' => $d->url])
            ->values()
            ->all();
    }
}
