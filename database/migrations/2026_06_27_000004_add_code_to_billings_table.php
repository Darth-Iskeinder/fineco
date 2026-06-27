<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Стабильный код режима тарификации на справочнике биллингов.
     * Логика цен переключается по этому коду, а не по русскому названию
     * (название редактируемо в настройках, код — нет).
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name')->index();
        });

        // Бэкфилл 4 сидовых значений по умолчанию (имя → код).
        $map = [
            'Входит в абонентку'      => 'included',
            'Считается по количеству' => 'by_quantity',
            'Доп.услуга'              => 'addon',
            'Не тарифицируется'       => 'none',
        ];
        foreach ($map as $name => $code) {
            DB::table('billings')->where('name', $name)->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
