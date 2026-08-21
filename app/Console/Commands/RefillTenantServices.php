<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Пересобирает каталог БП действующей фирмы по аккаунту-образцу.
 *
 * Нужно фирме, которую завели раньше, чем образец привели в порядок: она
 * получила при регистрации старый набор, работать по нему ещё не начинала,
 * и проще заменить каталог целиком, чем править БП по одному.
 *
 * Трогает только БП. Клиентов, сотрудников, сметы и задачи не касается —
 * да и не может: если на БП фирмы уже кто-то сослался, команда откажется
 * работать, потому что снос такого каталога покорёжил бы сметы.
 */
class RefillTenantServices extends Command
{
    protected $signature = 'tenant:refill-services
                            {--tenant= : Фирма (id или slug), которой пересобираем каталог БП}
                            {--keep-cost : Оставить цены образца (по умолчанию обнуляем — цены у каждой фирмы свои)}
                            {--skip-dictionaries : Не досоздавать недостающие группы, сферы и режимы тарификации}';

    protected $description = 'Заменить каталог БП фирмы набором из аккаунта-образца';

    public function handle(TenantTemplate $copier): int
    {
        try {
            $template = $copier->template();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $target = $this->resolveTarget();

        if (!$target) {
            $this->error('Фирма не найдена. Укажите --tenant=<id|slug>');

            return self::FAILURE;
        }

        $templateCount = $this->serviceCount($template);
        $targetCount   = $this->serviceCount($target);

        $this->line("Образец: <info>{$template->name}</info> ({$templateCount} БП)"
            . "  →  фирма: <info>{$target->name}</info> ({$targetCount} БП сейчас)");

        // Проверяем до подтверждения: если БП в работе, спрашивать не о чем.
        $inUse = $copier->servicesInUse($target);

        if ($inUse) {
            $this->error("БП фирмы «{$target->name}» уже взяты в работу — заменять каталог нельзя:");

            foreach ($inUse as $label => $count) {
                $this->line("  · {$label}: {$count}");
            }

            $this->newLine();
            $this->line('Отжившие БП в работающей фирме <comment>архивируются</comment>, а не удаляются:');
            $this->line('текущий месяц клиенты дорабатывают, со следующего задачи не заводятся.');

            return self::FAILURE;
        }

        $resetCost    = !$this->option('keep-cost');
        $dictionaries = !$this->option('skip-dictionaries');

        $this->line($resetCost
            ? 'Цены БП обнуляем — фирма проставит свои.'
            : '<comment>Цены БП переносим как есть</comment> (--keep-cost).');
        $this->line($dictionaries
            ? 'Недостающие группы, сферы и режимы тарификации досоздадим.'
            : '<comment>Справочники не трогаем</comment> (--skip-dictionaries).');

        if ($this->input->isInteractive() && !$this->confirm(
            "Каталог БП фирмы «{$target->name}» будет удалён ({$targetCount} шт.) и заменён набором образца ({$templateCount} шт.). Продолжить?"
        )) {
            $this->line('Отменено.');

            return self::SUCCESS;
        }

        try {
            $result = $copier->refillServices($target, $resetCost, $dictionaries);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Удалено БП: <info>{$result['deleted']}</info>   залито из образца: <info>{$result['copied']}</info>");

        foreach ($result['dictionaries'] as $table => $count) {
            $this->line("Досоздано в справочнике <info>{$table}</info>: {$count}");
        }

        // Название, которого нет и у образца, взять неоткуда. Для режима тарификации
        // это ощутимо: без него цена считается по собственной стоимости БП, молча.
        foreach ($result['unresolved'] as $missing) {
            $this->warn("Не нашлось в справочнике образца, заведите руками: {$missing}");
        }

        $this->newLine();
        $this->info('Готово. Цены и ставки фирма проставляет сама — из образца они не переносятся.');

        return self::SUCCESS;
    }

    private function resolveTarget(): ?Tenant
    {
        $value = $this->option('tenant');

        if (!$value) {
            return null;
        }

        return Tenant::real()
            ->where(is_numeric($value) ? 'id' : 'slug', $value)
            ->first();
    }

    private function serviceCount(Tenant $tenant): int
    {
        return (int) DB::table('services')->where('tenant_id', $tenant->id)->count();
    }
}
