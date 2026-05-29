<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Статус клиента (из справочника)
            $table->foreignId('client_status_id')
                ->nullable()
                ->after('is_active')
                ->constrained('client_statuses')
                ->nullOnDelete();

            // Категория налогоплательщика (из справочника)
            $table->foreignId('taxpayer_category_id')
                ->nullable()
                ->after('taxpayer_category')
                ->constrained('taxpayer_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['client_status_id']);
            $table->dropColumn('client_status_id');
            $table->dropForeign(['taxpayer_category_id']);
            $table->dropColumn('taxpayer_category_id');
        });
    }
};
