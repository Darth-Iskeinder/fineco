<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Новые условия клиента (триггеры подтягивания соответствующих БП в смету).
     * Остальные условия (маркетплейсы, импорт, экспорт, ПВТ, ПКИ, сотрудники) уже есть.
     */
    private array $columns = [
        'has_insurance_policy',
        'has_mbt',
        'has_crypto_exchange',
        'has_payment_aggregators',
        'has_production',
        'has_management_report',
    ];

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $after = 'has_alcohol';
            foreach ($this->columns as $col) {
                $table->boolean($col)->default(false)->after($after);
                $after = $col;
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
