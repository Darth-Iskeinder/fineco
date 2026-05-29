<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->string('color', 30)->default('slate')->after('name');
            $table->boolean('closes_service')->default(false)->after('color');
            $table->smallInteger('sort_order')->default(0)->after('closes_service');
        });
    }

    public function down(): void
    {
        Schema::table('client_statuses', function (Blueprint $table) {
            $table->dropColumn(['color', 'closes_service', 'sort_order']);
        });
    }
};
