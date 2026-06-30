<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Условия БП «ККМ / Касса» и «Алкоголь» — соответствуют характеристикам клиента
     * has_kkm и has_alcohol, которые уже были, но не имели парных условий на услуге.
     * См. Service::SPECIAL_FLAGS.
     */
    private array $columns = [
        'is_kkm',
        'is_alcohol',
    ];

    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $after = 'is_special_reporting';
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
