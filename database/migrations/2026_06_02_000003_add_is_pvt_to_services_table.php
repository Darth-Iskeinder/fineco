<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Признак «БП относится к режиму ПВТ»: такие БП подтягиваются в смету,
     * если у клиента включён флаг ПВТ (clients.pvt_mode).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_pvt')->default(false)->after('allows_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_pvt');
        });
    }
};
