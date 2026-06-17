<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->unsignedInteger('actual_quantity')->nullable()->after('paused_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropColumn('actual_quantity');
        });
    }
};
