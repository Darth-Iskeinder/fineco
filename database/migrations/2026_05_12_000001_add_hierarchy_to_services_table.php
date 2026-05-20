<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('services')->nullOnDelete();
            $table->boolean('allows_quantity')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'allows_quantity']);
        });
    }
};
