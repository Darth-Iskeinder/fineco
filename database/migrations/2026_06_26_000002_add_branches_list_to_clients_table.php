<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Филиалы клиента = список отдельных НО, в которые сдаётся часть отчётов.
     * Храним структурой [{no_code, city}], а не числом: коды НО нужны сотруднику,
     * чтобы не забыть сдать «филиальные» отчёты (НСП, 161 форма) в каждый НО.
     * Поэтому branches_count (просто число) становится не нужен.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('branches')->nullable()->after('has_branches');
            $table->dropColumn('branches_count');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('branches');
            $table->unsignedSmallInteger('branches_count')->nullable()->after('has_branches');
        });
    }
};
