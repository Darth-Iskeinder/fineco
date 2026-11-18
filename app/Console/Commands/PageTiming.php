<?php

namespace App\Console\Commands;

use App\Support\PageTimer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Отчёт по скорости страниц и выключатель счётчика.
 *
 * Без флагов только показывает: включён ли счётчик и таблицу за последние дни.
 * Сверху страницы, на которые люди в сумме ждали дольше всего: чинить их первыми.
 */
class PageTiming extends Command
{
    protected $signature = 'page-timing
        {--days=7 : За сколько последних дней}
        {--tenant= : Только одна фирма (id)}
        {--limit=30 : Сколько страниц показать}
        {--on : Включить счётчик}
        {--off : Выключить счётчик}';

    protected $description = 'Скорость страниц: отчёт и выключатель счётчика';

    public function handle(): int
    {
        if ($this->option('on') && $this->option('off')) {
            $this->error('Выберите что-то одно: --on или --off');

            return self::FAILURE;
        }

        if ($this->option('off') && !is_file(PageTimer::offFlag())) {
            if (@file_put_contents(PageTimer::offFlag(), now()->toDateTimeString()) === false) {
                $this->error('Не удалось создать ' . PageTimer::offFlag() . ' (права на папку?)');

                return self::FAILURE;
            }
        }

        if ($this->option('on') && is_file(PageTimer::offFlag())) {
            if (!@unlink(PageTimer::offFlag())) {
                $this->error('Не удалось удалить ' . PageTimer::offFlag() . ' (права на файл?)');

                return self::FAILURE;
            }
        }

        $this->line('Счётчик ' . (PageTimer::enabled() ? 'включён' : 'выключен'));

        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays($days - 1)->toDateString();

        $rows = DB::table('page_timings')
            ->where('day', '>=', $from)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->groupBy('method', 'route')
            ->select('method', 'route')
            ->selectRaw('SUM(hits) AS hits, SUM(total_ms) AS total_ms, MAX(max_ms) AS max_ms')
            ->selectRaw('SUM(under_300) AS under_300, SUM(under_1000) AS under_1000')
            ->orderByDesc('total_ms')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->line("С {$from} записей нет");

            return self::SUCCESS;
        }

        $this->line("С {$from}, сверху страницы, где в сумме ждали дольше всего");

        $this->table(
            ['Страница', 'Открытий', 'Среднее, с', 'До 0,3 с', 'До 1 с', 'Худшее, с', 'Всего ждали, мин'],
            $rows->map(fn ($r) => [
                $r->method . ' /' . ltrim($r->route, '/'),
                $r->hits,
                self::sec($r->total_ms / max(1, $r->hits)),
                self::pct($r->under_300, $r->hits),
                self::pct($r->under_1000, $r->hits),
                self::sec($r->max_ms),
                number_format($r->total_ms / 60000, 1, ',', ''),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private static function sec(float $ms): string
    {
        return number_format($ms / 1000, 2, ',', '');
    }

    private static function pct(int|string $part, int|string $all): string
    {
        return (int) floor(100 * (int) $part / max(1, (int) $all)) . '%';
    }
}
