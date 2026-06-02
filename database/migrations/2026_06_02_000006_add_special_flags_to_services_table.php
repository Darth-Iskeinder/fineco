<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Доп. условия (особые режимы) на БП. Такие БП подтягиваются в смету,
     * если у клиента включён соответствующий флаг. См. Service::SPECIAL_FLAGS.
     */
    private array $columns = [
        'is_insurance_policy',
        'is_marketplaces',
        'is_mbt',
        'is_crypto_exchange',
        'is_import_eaeu',
        'is_import_third',
        'is_export',
        'is_payment_aggregators',
        'is_production',
        'is_management_report',
    ];

    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $after = 'is_employees';
            foreach ($this->columns as $col) {
                $table->boolean($col)->default(false)->after($after);
                $after = $col;
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
