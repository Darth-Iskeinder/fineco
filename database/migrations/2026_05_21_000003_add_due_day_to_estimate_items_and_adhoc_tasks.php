<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('due_day')->nullable()->after('periodicity');
        });

        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('due_day')->nullable()->after('month');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('due_day');
        });

        Schema::table('buh_adhoc_tasks', function (Blueprint $table) {
            $table->dropColumn('due_day');
        });
    }
};
