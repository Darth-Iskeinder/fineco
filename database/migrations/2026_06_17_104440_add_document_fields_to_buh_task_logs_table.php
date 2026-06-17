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
            $table->string('document_path')->nullable()->after('actual_quantity');
            $table->string('document_name')->nullable()->after('document_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buh_task_logs', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name']);
        });
    }
};
