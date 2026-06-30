<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Характеристика бизнеса «Валютные операции (услуги)» — только информационный
     * признак в карточке клиента. НЕ является особым условием БП (нет парного is_*
     * на услуге и записи в Service::SPECIAL_FLAGS), поэтому в смету ничего не тянет.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('has_currency_operations')->default(false)->after('has_special_reporting');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('has_currency_operations');
        });
    }
};
