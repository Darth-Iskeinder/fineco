<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Категория налогоплательщика: два набора по три записи сводим в один.
 *
 * На бою 01.06.2026 справочник заполнили руками через экран настроек длинными
 * названиями («Малый субъект (до 8 млн)»). 31.07.2026 миграция fill_taxpayer_categories
 * искала запись по точному названию «Малый», не нашла и завела короткие рядом. Обоими
 * наборами пользуются (на 02.11.2026: 335 клиентов на длинных, 135 на коротких), и в
 * карточке каждая категория показывалась дважды.
 *
 * Оставляем длинные названия, так решил Искендер: пороги помогают выбрать. Клиентов с
 * короткой записи переводим на парную длинную, короткую удаляем, только когда на неё
 * никто не ссылается. Где длинной нет (локально, в тестах), короткую переименовываем:
 * набор везде один.
 *
 * Ищем по названию, а не по id: id на бою и локально разные. Справочник общий для всех
 * фирм, поэтому и перенос идёт по всем, это не утечка между фирмами: меняется только
 * ссылка на общую запись. Удалённые клиенты (deleted_at) переносятся тоже, иначе запись
 * не удалить.
 *
 * Каждый перенос пишется в taxpayer_category_merges: down() по нему возвращает клиентов
 * ровно туда, где они были. Повторный запуск ничего не портит: дублей уже нет.
 */
return new class extends Migration
{
    /** Короткое название => длинное, которое остаётся. Первое длинное в списке итоговое. */
    private const PAIRS = [
        'Малый'   => ['Малый субъект (до 8 млн)'],
        'Средний' => ['Средний субъект (8-50 млн)', 'Средний субъект( 8-50млн)'],
        'Крупный' => ['Крупный субъект (от 50 млн)'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('taxpayer_category_merges')) {
            Schema::create('taxpayer_category_merges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id');
                $table->string('from_name');
                $table->unsignedBigInteger('to_category_id');
                $table->timestamp('created_at')->nullable();
            });
        }

        DB::transaction(function () {
            foreach (self::PAIRS as $short => $longNames) {
                $this->merge($short, $longNames[0], $longNames);
            }
        });
    }

    private function merge(string $short, string $final, array $longNames): void
    {
        $long    = DB::table('taxpayer_categories')->whereIn('name', $longNames)->orderBy('id')->first();
        $shortId = DB::table('taxpayer_categories')->where('name', $short)->value('id');

        if (!$long) {
            // Длинной нет: переименовываем короткую, клиенты остаются на своей записи.
            if ($shortId) {
                DB::table('taxpayer_categories')->where('id', $shortId)->update(['name' => $final, 'updated_at' => now()]);
            }

            return;
        }

        if ($long->name !== $final) {
            DB::table('taxpayer_categories')->where('id', $long->id)->update(['name' => $final, 'updated_at' => now()]);
        }

        if (!$shortId) {
            return;
        }

        $clientIds = DB::table('clients')->where('taxpayer_category_id', $shortId)->pluck('id');

        foreach ($clientIds->chunk(500) as $chunk) {
            DB::table('taxpayer_category_merges')->insert($chunk->map(fn ($id) => [
                'client_id'      => $id,
                'from_name'      => $short,
                'to_category_id' => $long->id,
                'created_at'     => now(),
            ])->values()->all());

            DB::table('clients')->whereIn('id', $chunk)->update(['taxpayer_category_id' => $long->id]);
        }

        if (!DB::table('clients')->where('taxpayer_category_id', $shortId)->exists()) {
            DB::table('taxpayer_categories')->where('id', $shortId)->delete();
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('taxpayer_category_merges')) {
            return;
        }

        DB::transaction(function () {
            foreach (self::PAIRS as $short => $longNames) {
                $moved = DB::table('taxpayer_category_merges')->where('from_name', $short)->pluck('client_id');

                if ($moved->isEmpty()) {
                    continue;
                }

                $shortId = DB::table('taxpayer_categories')->where('name', $short)->value('id')
                    ?? DB::table('taxpayer_categories')->insertGetId([
                        'name' => $short, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                foreach ($moved->chunk(500) as $chunk) {
                    DB::table('clients')->whereIn('id', $chunk)->update(['taxpayer_category_id' => $shortId]);
                }
            }

            // Опечатку возвращаем, только если сводили на бою: там прежнее название было таким.
            if (DB::table('taxpayer_category_merges')->where('from_name', 'Средний')->exists()) {
                DB::table('taxpayer_categories')->where('name', 'Средний субъект (8-50 млн)')
                    ->update(['name' => 'Средний субъект( 8-50млн)', 'updated_at' => now()]);
            }
        });

        // Переименование без переноса (где длинных не было) не откатываем: записи и
        // клиенты на месте, отличается только название.
        Schema::drop('taxpayer_category_merges');
    }
};
