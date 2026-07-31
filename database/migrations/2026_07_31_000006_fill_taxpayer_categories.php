<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Категория налогоплательщика: чиним начатый и брошенный переход.
 *
 * Было два поля одновременно. Старое — текстовая колонка clients.taxpayer_category
 * со списком, зашитым в код (small/medium/large). Новое — справочник
 * taxpayer_categories, пустой. Карточка клиента показывала значение из старого,
 * а выпадающий список брала из нового — то есть выбрать категорию было нельзя
 * вообще ни у одного клиента.
 *
 * Здесь заполняем справочник тремя значениями ГНС и переносим на него тех
 * клиентов, у кого проставлено старое значение. Старую колонку не трогаем:
 * при откате данные должны остаться на месте, а чистка мусора — отдельная задача.
 *
 * Справочник закрыт на редактирование (раздел настроек убран), поэтому пометка
 * «чей» ему не нужна: список задаёт государство, он общий для всех аккаунтов.
 */
return new class extends Migration
{
    /** Старое значение в коде => название в справочнике. */
    private const MAP = [
        'small'  => 'Малый',
        'medium' => 'Средний',
        'large'  => 'Крупный',
    ];

    public function up(): void
    {
        foreach (self::MAP as $legacy => $name) {
            $id = DB::table('taxpayer_categories')->where('name', $name)->value('id');

            if (!$id) {
                $id = DB::table('taxpayer_categories')->insertGetId([
                    'name'       => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Переносим клиентов со старого текстового поля на справочник.
            // Тех, у кого связь уже стоит, не трогаем.
            DB::table('clients')
                ->where('taxpayer_category', $legacy)
                ->whereNull('taxpayer_category_id')
                ->update(['taxpayer_category_id' => $id]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('taxpayer_categories')
            ->whereIn('name', array_values(self::MAP))
            ->pluck('id');

        // Снимаем связь только у тех, кого сами и проставили; старое текстовое
        // значение осталось на месте, так что ничего не теряется.
        DB::table('clients')->whereIn('taxpayer_category_id', $ids)->update(['taxpayer_category_id' => null]);

        DB::table('taxpayer_categories')->whereIn('id', $ids)->delete();
    }
};
