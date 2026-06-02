<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Признак «БП относится к режиму ПКИ»: такие БП подтягиваются в смету,
     * если у клиента включён флаг ПКИ (clients.pki_mode).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_pki')->default(false)->after('is_pvt');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_pki');
        });
    }
};
