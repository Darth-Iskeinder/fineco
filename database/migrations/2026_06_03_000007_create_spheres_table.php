<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spheres', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $now = now();
        $names = [
            'Банк, касса и платежи',
            'Документооборот и первичка',
            'ЭСФ',
            '1С и закрытие',
            'ЗП, кадры и соцфонд',
            'Налоги и отчетность',
            'ПВТ / ПКИ',
            'Спецпроцедуры',
            'ВЭД',
            'МБТ',
            'Маркетплейсы',
            'Криптообменники',
            'Производство',
            'Контроль и аудит',
            'Управленческие отчеты',
            'Биллинг ФинЭко',
        ];
        DB::table('spheres')->insert(array_map(
            fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            $names,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('spheres');
    }
};
