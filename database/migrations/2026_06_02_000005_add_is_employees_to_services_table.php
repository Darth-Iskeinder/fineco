<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Признак «БП связан с сотрудниками»: такие БП подтягиваются в смету,
     * если у клиента включён флаг «Сотрудники» (clients.has_employees).
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_employees')->default(false)->after('is_pki');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_employees');
        });
    }
};
