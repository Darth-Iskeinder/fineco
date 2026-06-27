<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Связь БП → ставка из справочника. Для платных режимов биллинга
     * (by_quantity / addon) цена и единица берутся из ставки, а не из cost.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('rate_id')->nullable()->after('cost')
                ->constrained('rates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_id');
        });
    }
};
