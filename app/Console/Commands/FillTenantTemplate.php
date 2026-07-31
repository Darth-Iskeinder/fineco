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
 */
class FillTenantTemplate extends Command
{
    protected $signature = 'tenant:fill-template
                            {--from= : Аккаунт-источник (id или slug). По умолчанию — первая живая фирма}
                            {--replace : Очистить образец перед наполнением}';

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
            $copied = $copier->fillFrom($source);
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
