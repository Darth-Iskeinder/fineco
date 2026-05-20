<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tariff', function (Blueprint $table) {
            // Бесплатный лимит включённых единиц в тарифе (0 = без лимита)
            $table->integer('free_limit')->default(0)->after('tariff_id');
            // Переопределение базовой цены услуги для конкретного тарифа
            $table->decimal('price_override', 12, 2)->nullable()->after('free_limit');
        });

        Schema::table('services', function (Blueprint $table) {
            // Ступенчатые цены: [{"from": 1, "to": 10, "price": 500}, ...]
            $table->json('pricing_rules')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('service_tariff', function (Blueprint $table) {
            $table->dropColumn(['free_limit', 'price_override']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('pricing_rules');
        });
    }
};
