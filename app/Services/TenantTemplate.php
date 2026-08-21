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

    /** Очистить образец: только справочники, рабочих данных в нём нет. */
    public function clearTemplate(): void
    {
        $template = $this->template();

        DB::transaction(function () use ($template) {
            $serviceIds = DB::table('services')->where('tenant_id', $template->id)->pluck('id');

            DB::table('service_tax_system')->whereIn('service_id', $serviceIds)->delete();

            // Сперва подпункты: у родителя на них ссылка.
            DB::table('services')->where('tenant_id', $template->id)->whereNotNull('parent_id')->delete();
            DB::table('services')->where('tenant_id', $template->id)->delete();

            foreach (self::SIMPLE_TABLES as $table) {
                DB::table($table)->where('tenant_id', $template->id)->delete();
            }
        });
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
     * @param string[] $skipNames
     */
    private function copyServices(int $fromTenant, int $toTenant, array $skipNames = []): int
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
