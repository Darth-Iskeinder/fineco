<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Boolean flags — объём бизнеса (с количеством)
            $table->boolean('is_zero_movement')->default(false)->after('is_active');
            $table->boolean('has_employees')->default(false)->after('is_zero_movement');
            $table->unsignedSmallInteger('employees_count')->nullable()->after('has_employees');
            $table->boolean('has_kkm')->default(false)->after('employees_count');
            $table->unsignedSmallInteger('kkm_count')->nullable()->after('has_kkm');
            $table->boolean('has_marketplaces')->default(false)->after('kkm_count');
            $table->unsignedSmallInteger('marketplaces_count')->nullable()->after('has_marketplaces');

            // Boolean flags — режимы и особенности
            $table->boolean('import_eaeu')->default(false)->after('marketplaces_count');
            $table->boolean('import_third_countries')->default(false)->after('import_eaeu');
            $table->boolean('has_export')->default(false)->after('import_third_countries');
            $table->boolean('pvt_mode')->default(false)->after('has_export');
            $table->boolean('pki_mode')->default(false)->after('pvt_mode');
            $table->boolean('has_alcohol')->default(false)->after('pki_mode');

            // Строки
            $table->string('edo_operator', 255)->nullable()->after('has_alcohol');
            $table->string('client_folder_url', 500)->nullable()->after('edo_operator');
            $table->text('access_instructions')->nullable()->after('client_folder_url');

            // JSON
            $table->json('related_persons')->nullable()->after('access_instructions');
            $table->json('contacts')->nullable()->after('related_persons');
            $table->json('extra_fields')->nullable()->after('contacts');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'is_zero_movement', 'has_employees', 'employees_count',
                'has_kkm', 'kkm_count', 'has_marketplaces', 'marketplaces_count',
                'import_eaeu', 'import_third_countries', 'has_export',
                'pvt_mode', 'pki_mode', 'has_alcohol',
                'edo_operator', 'client_folder_url', 'access_instructions',
                'related_persons', 'contacts', 'extra_fields',
            ]);
        });
    }
};
