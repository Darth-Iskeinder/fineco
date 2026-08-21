<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantTemplate;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Наполняет аккаунт-образец каталогом действующей фирмы.
 *
 * Запускается один раз на сервере, чтобы образцу было что отдавать новым
 * аккаунтам. Дальше набор правится руками через обычные настройки.
 *
 * Клиентов, сотрудников, задач и смет не касается — только справочники.
 * Цены (тарифы и ставки) в образец не попадают намеренно.
 *
 * Рабочий каталог живой фирмы шире стартового набора: в нём есть БП, помеченные
 * к выбросу прямо в названии. Такие отсеиваются флагом --skip-name, например
 * `--skip-name=удалить`.
 */
class FillTenantTemplate extends Command
{
    protected $signature = 'tenant:fill-template
                            {--from= : Аккаунт-источник (id или slug). По умолчанию — первая живая фирма}
                            {--replace : Очистить образец перед наполнением}
                            {--skip-name=* : Не переносить БП, в названии которых есть эта подстрока (регистр не важен)}';

    protected $description = 'Наполнить аккаунт-образец каталогом действующей фирмы';

    public function handle(TenantTemplate $copier): int
    {
        try {
            $template = $copier->template();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = $this->resolveSource();

        if (!$source) {
            $this->error('Аккаунт-источник не найден');

            return self::FAILURE;
        }

        $this->line("Источник: <info>{$source->name}</info>  →  образец: <info>{$template->name}</info>");

        $skipNames = array_values(array_filter(array_map('trim', $this->option('skip-name')), 'strlen'));

        if ($skipNames) {
            $skipped = $copier->servicesToSkip($source, $skipNames);

            $this->line('Фильтр названий: <comment>' . implode('</comment>, <comment>', $skipNames) . '</comment>');

            if (!$skipped) {
                $this->warn('Под фильтр не попал ни один БП — проверьте, тот ли источник и то ли слово.');
            } else {
                $this->line('Не переносим ' . count($skipped) . ' БП (подпункты уедут вместе с ними):');
                foreach ($skipped as $name) {
                    $this->line("  · {$name}");
                }
            }

            if ($this->input->isInteractive() && !$this->confirm('Список верный, продолжаем?', true)) {
                $this->line('Отменено.');

                return self::SUCCESS;
            }
        }

        if ($this->option('replace')) {
            if ($this->input->isInteractive()
                && !$this->confirm('Содержимое образца будет удалено и заменено. Продолжить?')) {
                $this->line('Отменено.');

                return self::SUCCESS;
            }

            $copier->clearTemplate();
            $this->line('Образец очищен.');
        }

        try {
            $copied = $copier->fillFrom($source, $skipNames);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('Если набор надо пересобрать — запустите с флагом <comment>--replace</comment>.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Что скопировано', 'Строк'],
            collect($copied)->map(fn ($count, $table) => [$this->label($table), $count])->values()->all(),
        );

        $this->newLine();
        $this->info('Готово. Цены (тарифы и ставки) в образец не копируются — они у каждой фирмы свои.');
        $this->line('Проверьте набор в настройках и уберите лишнее, если что-то попало по ошибке.');

        return self::SUCCESS;
    }

    private function resolveSource(): ?Tenant
    {
        $from = $this->option('from');

        if (!$from) {
            return Tenant::real()->orderBy('id')->first();
        }

        return Tenant::real()
            ->where(is_numeric($from) ? 'id' : 'slug', $from)
            ->first();
    }

    private function label(string $table): string
    {
        return [
            'billings'       => 'Режимы тарификации',
            'activity_types' => 'Виды деятельности',
            'spheres'        => 'Сферы',
            'service_groups' => 'Группы',
            'services'       => 'Бизнес-процессы',
        ][$table] ?? $table;
    }
}
