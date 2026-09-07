<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер компании: свой номер клиента, который фирма ведёт руками.
 *
 * Появился вместо колонки с id в списке клиентов. Id раскрывал, сколько всего
 * компаний в системе, а бухфирмам удобно звать клиентов своими номерами, и они
 * ни с какой внутренней нумерацией не совпадают.
 *
 * Уникален внутри своей фирмы, как ИНН: две фирмы спокойно держат номер 1,
 * внутри одной фирмы двух первых номеров быть не должно. Пустые значения
 * уникальности не мешают: и MySQL, и PostgreSQL сравнивают NULL как разные,
 * поэтому у всех старых клиентов поле остаётся пустым.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('company_number')->nullable()->after('inn');
            $table->unique(['tenant_id', 'company_number'], 'clients_tenant_company_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_tenant_company_number_unique');
            $table->dropColumn('company_number');
        });
    }
};
