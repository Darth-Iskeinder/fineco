<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Новые характеристики бизнеса клиента + соответствующие условия БП.
     * Каждый признак — это и колонка-флаг на клиенте, и колонка-условие is_* на услуге
     * (триггер подтягивания БП в смету, см. Service::SPECIAL_FLAGS).
     *
     * Часть признаков «с количеством» — у клиента дополнительно поле *_count.
     */

    /** Признаки с количеством: client has_* + *_count, service is_*. */
    private array $countFlags = [
        'fixed_assets', // ОС
        'fuel',         // Учёт ГСМ
        'loans',        // Кредиты / депозиты
        'branches',     // Филиалы
    ];

    /** Признаки-переключатели: client has_*, service is_*. */
    private array $toggleFlags = [
        'excise',                 // Акциз / ЭТТН
        'nonresident_services',   // Нерезиденты ДИО / эл.услуги
        'property',               // Имущество / транспорт / земля
        'bank_client',            // Банк-клиент (платёжки)
        'separate_books',         // Раздельные базы УУ/ТК/НУ/УТ
        'nonstandard_contracts',  // Нестандартные договоры
        'foreign_trade',          // Внешнеторговая деятельность
        'vat_refund',             // Возмещение НДС
        'special_reporting',      // Спец. отчётность
    ];

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $after = 'has_management_report';
            foreach ($this->countFlags as $flag) {
                $table->boolean("has_{$flag}")->default(false)->after($after);
                $table->unsignedSmallInteger("{$flag}_count")->nullable()->after("has_{$flag}");
                $after = "{$flag}_count";
            }
            foreach ($this->toggleFlags as $flag) {
                $table->boolean("has_{$flag}")->default(false)->after($after);
                $after = "has_{$flag}";
            }
        });

        Schema::table('services', function (Blueprint $table) {
            $after = 'is_management_report';
            foreach ([...$this->countFlags, ...$this->toggleFlags] as $flag) {
                $table->boolean("is_{$flag}")->default(false)->after($after);
                $after = "is_{$flag}";
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach ($this->countFlags as $flag) {
                $table->dropColumn("has_{$flag}");
                $table->dropColumn("{$flag}_count");
            }
            foreach ($this->toggleFlags as $flag) {
                $table->dropColumn("has_{$flag}");
            }
        });

        Schema::table('services', function (Blueprint $table) {
            foreach ([...$this->countFlags, ...$this->toggleFlags] as $flag) {
                $table->dropColumn("is_{$flag}");
            }
        });
    }
};
