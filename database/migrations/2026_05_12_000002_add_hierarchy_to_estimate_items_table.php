<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('estimate_id')
                ->constrained('estimate_items')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->after('parent_id')
                ->constrained('services')->nullOnDelete();
            $table->boolean('is_extra')->default(false)->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['service_id']);
            $table->dropColumn(['parent_id', 'service_id', 'is_extra']);
        });
    }
};
