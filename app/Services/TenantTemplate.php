<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Копирование стартового набора справочников из аккаунта-образца в новый аккаунт.
 *
 * Что копируем и почему:
 *   - биллинги — обязательно. Это не справочник, а механика: по коду режима
 *     считается цена БП. Без них цена молча уедет на собственную стоимость БП.
 *   - виды деятельности, сферы, группы — чтобы новая фирма не смотрела в пустой
 *     экран и могла сразу разложить работу по полочкам.
 *   - бизнес-процессы вместе с подпунктами и привязкой к режимам налогообложения
 *     — базовый набор, с которого можно начать работать в первый день.
 *
 * Что НЕ копируем:
 *   - тарифы и ставки. Это цены, они у каждой фирмы свои, и показывать чужие
 *     нельзя. У скопированных БП ссылка на ставку снимается — новая фирма
 *     заводит свои ставки и привязывает сама.
 *   - шаблон чек-листа аудита. Методика проверки — личное дело фирмы.
 *   - клиентов, сотрудников, сметы, задачи. В образце их нет и быть не должно.
 */
class TenantTemplate
{
    /** Простые справочники: копируются построчно, ссылок ни на что не имеют. */
    private const SIMPLE_TABLES = [
        'billings',
        'activity_types',
        'spheres',
        'service_groups',
    ];

    /** Колонки, которые при копировании строки задаются заново, а не переносятся. */
    private const SKIP_COLUMNS = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * Где остаётся след, если БП взяли в работу. Тот же набор, что проверяет
     * интерфейс перед удалением БП (SettingsController::serviceIsInUse).
     */
    private const USAGE_TABLES = [
        'estimate_items'           => 'позиции смет',
        'client_service_schedules' => 'индивидуальные расписания',
        'task_reminders'           => 'напоминания',
        'buh_adhoc_tasks'          => 'разовые задачи',
    ];

    /**
     * Строковые поля БП и справочники, откуда берутся их значения.
     *
     * Это не внешние ключи, а тексты: БП хранит название режима, сферы и группы.
     * Приехавший в чужую фирму БП с названием, которого нет в её справочнике,
     * ошибки не даст — просто перестанет находиться по фильтрам, а режим
     * тарификации не разрешится в код, и цена молча посчитается по cost.
     */
    private const SERVICE_DICTIONARIES = [
        'billing'       => 'billings',
        'sphere'        => 'spheres',
        'service_group' => 'service_groups',
    ];

    /** Аккаунт-образец. */
    public function template(): Tenant
    {
        $template = Tenant::template()->first();

        if (!$template) {
            throw new RuntimeException(
                'Аккаунт-образец не найден — новому аккаунту неоткуда взять стартовый набор'
            );
        }

        return $template;
    }

    /**
     * Переносит стартовый набор в аккаунт. Возвращает счётчики по таблицам.
     *
     * Повторный вызов на непустом аккаунте запрещён: набор копируется один раз,
     * при создании. Иначе фирма получила бы второй комплект справочников поверх
     * своего, уже поправленного.
     */
    public function copyTo(Tenant $target): array
    {
        if ($target->isTemplate()) {
            throw new RuntimeException('Копировать образец сам в себя нельзя');
        }

        return $this->copy($this->template(), $target);
    }

    /**
     * Наполнить образец каталогом действующей фирмы. Нужно один раз, чтобы
     * образцу было что отдавать новым аккаунтам; дальше набор правится руками.
     *
     * $skipNames — куски названий БП, которые в образец не переносим. Живая фирма
     * помечает отжившие БП прямо в названии («… (удалить)»), и в стартовый набор
     * новых фирм такому попадать незачем. Сравнение — вхождение подстроки без
     * учёта регистра; подпункты отсеянного БП уезжают вместе с ним.
     *
     * @param string[] $skipNames
     */
    public function fillFrom(Tenant $source, array $skipNames = []): array
    {
        if ($source->isTemplate()) {
            throw new RuntimeException('Образец нельзя наполнить из самого себя');
        }

        return $this->copy($source, $this->template(), $skipNames);
    }

    /**
     * Названия БП источника, которые фильтр отсеет — показать до копирования.
     *
     * Подпунктов отсеянных родителей здесь нет: они отваливаются уже при
     * копировании, потому что родителя в образце не окажется.
     *
     * @param  string[] $skipNames
     * @return string[]
     */
    public function servicesToSkip(Tenant $source, array $skipNames): array
    {
        if (!$skipNames) {
            return [];
        }

        return DB::table('services')
            ->where('tenant_id', $source->id)
            ->orderBy('id')
            ->pluck('name')
            ->filter(fn ($name) => $this->nameMatches($name, $skipNames))
            ->values()
            ->all();
    }

    /**
     * Взяты ли БП фирмы хоть где-нибудь в работу: [что нашли => сколько].
     *
     * Пустой массив — каталог можно сносить целиком, никто на него не смотрит.
     *
     * @return array<string,int>
     */
    public function servicesInUse(Tenant $target): array
    {
        $ids = DB::table('services')->where('tenant_id', $target->id)->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $found = [];

        foreach (self::USAGE_TABLES as $table => $label) {
            $count = DB::table($table)->whereIn('service_id', $ids)->count();

            if ($count > 0) {
                $found[$label] = $count;
            }
        }

        return $found;
    }

    /**
     * Пересобрать каталог БП действующей фирмы по образцу: свои удалить, набор
     * образца залить. Нужно фирме, которую завели, но ещё не запускали в работу.
     *
     * Отказываемся, если БП уже взяты в работу, — по той же причине, по которой
     * интерфейс не даёт удалить работающий БП: у позиций сметы ссылка снимется
     * (внешний ключ nullOnDelete), позиция останется без расписания и превратится
     * в разовую задачу текущего месяца, а индивидуальные расписания клиентов
     * уйдут каскадом. Для работающего каталога есть архивация, а не снос.
     *
     * $resetCost — обнулить цену: в образце лежат суммы фирмы-донора, и переносить
     * их другой фирме нельзя (ставки не переносятся ровно по той же причине).
     * $fillDictionaries — досоздать недостающие группы, сферы и режимы, чтобы
     * приехавшие БП не остались с названиями, которых у фирмы нет.
     *
     * @return array{deleted:int, copied:int, dictionaries:array<string,int>, unresolved:string[]}
     */
    public function refillServices(Tenant $target, bool $resetCost = true, bool $fillDictionaries = true): array
    {
        if ($target->isTemplate()) {
            throw new RuntimeException('Образец пересобирается командой tenant:fill-template, а не этой');
        }

        $template = $this->template();
        $inUse    = $this->servicesInUse($target);

        if ($inUse) {
            $details = collect($inUse)->map(fn ($count, $label) => "{$label}: {$count}")->implode(', ');

            throw new RuntimeException(
                "БП фирмы «{$target->name}» уже взяты в работу ({$details}) — сносить каталог нельзя. "
                . 'Отжившие БП архивируются, а не удаляются.'
            );
        }

        return DB::transaction(function () use ($template, $target, $resetCost, $fillDictionaries) {
            $deleted = $this->deleteServices($target->id);
            $copied  = $this->copyServices($template->id, $target->id, [], $resetCost);

            $dictionaries = ['added' => [], 'unresolved' => []];

            if ($fillDictionaries) {
                $dictionaries = $this->fillMissingDictionaries($template, $target);
            }

            return [
                'deleted'      => $deleted,
                'copied'       => $copied,
                'dictionaries' => $dictionaries['added'],
                'unresolved'   => $dictionaries['unresolved'],
            ];
        });
    }

    /** Очистить образец: только справочники, рабочих данных в нём нет. */
    public function clearTemplate(): void
    {
        $template = $this->template();

        DB::transaction(function () use ($template) {
            $this->deleteServices($template->id);

            foreach (self::SIMPLE_TABLES as $table) {
                DB::table($table)->where('tenant_id', $template->id)->delete();
            }
        });
    }

    /** Удалить БП аккаунта вместе с их привязками к режимам налогообложения. */
    private function deleteServices(int $tenantId): int
    {
        $serviceIds = DB::table('services')->where('tenant_id', $tenantId)->pluck('id');

        if ($serviceIds->isEmpty()) {
            return 0;
        }

        DB::table('service_tax_system')->whereIn('service_id', $serviceIds)->delete();

        // Сперва подпункты: у родителя на них ссылка.
        DB::table('services')->where('tenant_id', $tenantId)->whereNotNull('parent_id')->delete();
        DB::table('services')->where('tenant_id', $tenantId)->delete();

        return $serviceIds->count();
    }

    /**
     * Досоздать фирме недостающие группы, сферы и режимы тарификации — те, что
     * стоят в приехавших БП, но в справочниках фирмы отсутствуют.
     *
     * Строку берём из справочника источника целиком: у режима тарификации важно
     * не название, а код — по нему считается цена. Название, которого нет и у
     * источника, придумать не из чего — возвращаем его отдельным списком.
     *
     * @return array{added:array<string,int>, unresolved:string[]}
     */
    private function fillMissingDictionaries(Tenant $source, Tenant $target): array
    {
        $added      = [];
        $unresolved = [];

        foreach (self::SERVICE_DICTIONARIES as $column => $table) {
            $used = DB::table('services')
                ->where('tenant_id', $target->id)
                ->whereNotNull($column)
                ->distinct()
                ->pluck($column)
                ->filter()
                ->values();

            $have    = DB::table($table)->where('tenant_id', $target->id)->pluck('name');
            $missing = $used->diff($have);

            foreach ($missing as $name) {
                $row = DB::table($table)
                    ->where('tenant_id', $source->id)
                    ->where('name', $name)
                    ->first();

                if (!$row) {
                    $unresolved[] = "{$table}: {$name}";

                    continue;
                }

                DB::table($table)->insert($this->rowFor($row, $target->id));
                $added[$table] = ($added[$table] ?? 0) + 1;
            }
        }

        return ['added' => $added, 'unresolved' => $unresolved];
    }

    /** @param string[] $skipNames */
    private function copy(Tenant $source, Tenant $target, array $skipNames = []): array
    {
        if ($this->alreadyFilled($target)) {
            throw new RuntimeException(
                "В аккаунте «{$target->name}» уже есть справочники — повторное копирование удвоило бы их"
            );
        }

        return DB::transaction(function () use ($source, $target, $skipNames) {
            $copied = [];

            foreach (self::SIMPLE_TABLES as $table) {
                $copied[$table] = $this->copyTable($table, $source->id, $target->id);
            }

            $copied['services'] = $this->copyServices($source->id, $target->id, $skipNames);

            return $copied;
        });
    }

    /** Есть ли в аккаунте хоть что-то из копируемого набора. */
    private function alreadyFilled(Tenant $target): bool
    {
        foreach (array_merge(self::SIMPLE_TABLES, ['services']) as $table) {
            if (DB::table($table)->where('tenant_id', $target->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Построчная копия простого справочника. */
    private function copyTable(string $table, int $fromTenant, int $toTenant): int
    {
        $rows = DB::table($table)->where('tenant_id', $fromTenant)->get();

        foreach ($rows as $row) {
            DB::table($table)->insert($this->rowFor($row, $toTenant));
        }

        return $rows->count();
    }

    /**
     * Бизнес-процессы: сперва корневые, затем подпункты — им нужен новый id
     * родителя. Ссылка на ставку снимается: ставки не копируем, это цены.
     * Привязка к режимам налогообложения переносится как есть — режимы общие
     * для всех аккаунтов, их id одинаковы.
     *
     * $skipNames отсеивает БП по названию: сам БП не переносится, а его подпункты
     * отваливаются следом — родителя в новом аккаунте для них нет.
     *
     * $resetCost обнуляет цену: при переносе в другую фирму собственная стоимость
     * БП — такие же чужие деньги, как ставка, и ехать с каталогом не должна.
     *
     * @param string[] $skipNames
     */
    private function copyServices(int $fromTenant, int $toTenant, array $skipNames = [], bool $resetCost = false): int
    {
        $idMap = [];
        $count = 0;

        foreach ([null, 'children'] as $pass) {
            $query = DB::table('services')->where('tenant_id', $fromTenant);
            $rows  = ($pass === null ? $query->whereNull('parent_id') : $query->whereNotNull('parent_id'))
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                if ($this->nameMatches($row->name, $skipNames)) {
                    continue;
                }

                $data = $this->rowFor($row, $toTenant, ['parent_id', 'rate_id']);

                $data['parent_id'] = $row->parent_id ? ($idMap[$row->parent_id] ?? null) : null;
                $data['rate_id']   = null;

                if ($resetCost) {
                    $data['cost'] = 0;
                }

                // Подпункт без перенесённого родителя копировать некуда:
                // такой БП стал бы висеть сам по себе.
                if ($row->parent_id && !$data['parent_id']) {
                    continue;
                }

                $newId = DB::table('services')->insertGetId($data);
                $idMap[$row->id] = $newId;
                $count++;

                $this->copyTaxSystemLinks($row->id, $newId);
            }
        }

        return $count;
    }

    /**
     * Название БП попадает под фильтр отсева.
     *
     * Регистр не учитываем и сравниваем в PHP, а не через SQL LIKE: пометка в живом
     * каталоге пишется как придётся («(удалить)», «( Удалить)», «(УДАЛИТЬ)»), а LIKE
     * в PostgreSQL регистрозависим, в MySQL — нет. Промахнувшийся фильтр протащил бы
     * отжившие БП в стартовый набор каждой новой фирмы.
     *
     * @param string[] $skipNames
     */
    private function nameMatches(?string $name, array $skipNames): bool
    {
        foreach ($skipNames as $needle) {
            if ($needle !== '' && mb_stripos((string) $name, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Привязка БП к режимам налогообложения: от неё зависит подтягивание в смету. */
    private function copyTaxSystemLinks(int $fromServiceId, int $toServiceId): void
    {
        $links = DB::table('service_tax_system')->where('service_id', $fromServiceId)->pluck('tax_system_id');

        foreach ($links as $taxSystemId) {
            DB::table('service_tax_system')->insert([
                'service_id'    => $toServiceId,
                'tax_system_id' => $taxSystemId,
            ]);
        }
    }

    /** Строка-источник → строка для вставки: свой аккаунт, свежие даты, без id. */
    private function rowFor(object $row, int $toTenant, array $alsoSkip = []): array
    {
        $data = array_diff_key(
            (array) $row,
            array_flip(array_merge(self::SKIP_COLUMNS, $alsoSkip)),
        );

        return $data + [
            'tenant_id'  => $toTenant,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
