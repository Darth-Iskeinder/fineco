<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Память о смене режима налогообложения: что было раньше и когда сменили.
 *
 * Нужна смете: после смены человек должен пройтись по составу БП, потому что
 * само ничего не переключается. Раньше факт смены нигде не сохранялся, и смета
 * пыталась угадать его по данным — ругалась и на процессы, которые оставили
 * намеренно. Теперь она сообщает факт, а не строит догадки.
 *
 * Обе колонки пустые у всех: у клиентов, которых не трогали, напоминания нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('previous_tax_system_id')->nullable()->after('tax_system_id')
                ->constrained('tax_systems')->nullOnDelete();
            $table->date('tax_system_changed_at')->nullable()->after('previous_tax_system_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_tax_system_id');
            $table->dropColumn('tax_system_changed_at');
        });
    }
};
