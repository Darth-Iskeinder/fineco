<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Стабильный тип периодичности — на него опирается логика формы БП
     * (какие поля «Месяц»/«День» активны и как они выбираются).
     * Отображаемое имя (name) можно менять свободно, поведение завязано на kind.
     */
    public function up(): void
    {
        Schema::table('periodicities', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('name');
        });

        // Бэкфилл существующих значений по имени
        $map = [
            'Еженедельно'   => 'weekly',
            'Ежемесячно'    => 'monthly',
            'Ежеквартально' => 'quarterly',
            'Ежегодно'      => 'yearly',
        ];
        foreach ($map as $name => $kind) {
            DB::table('periodicities')->where('name', $name)->update(['kind' => $kind]);
        }
    }

    public function down(): void
    {
        Schema::table('periodicities', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
