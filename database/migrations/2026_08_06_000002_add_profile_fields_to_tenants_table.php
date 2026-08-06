<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Профиль фирмы: чем она представляется своим сотрудникам и своим клиентам.
 *
 * Отдельными колонками, а не в json `settings`: эти данные уходят в акты и
 * сметы, то есть в документы для клиента. По ним однажды придётся искать и
 * проверять заполненность — из json это делать неудобно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // `name` уже есть — это короткое название для интерфейса.
            $table->string('legal_name')->nullable()->after('name');
            $table->string('logo_path')->nullable()->after('legal_name');

            $table->string('inn', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();

            // Кто подписывает акты со стороны фирмы.
            $table->string('director_name')->nullable();

            $table->string('bank_name')->nullable();
            $table->string('bank_account', 50)->nullable();
            $table->string('bank_bik', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'logo_path', 'inn', 'address', 'phone', 'email',
                'director_name', 'bank_name', 'bank_account', 'bank_bik',
            ]);
        });
    }
};
