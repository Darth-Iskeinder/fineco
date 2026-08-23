<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Что мы ведём у клиента: бухучёт, налоговый учёт, расчёт ЗП. Отмечается
     * любое сочетание, отметки независимы.
     *
     * Отдельные колонки вместо массива в json намеренно: в бою PostgreSQL, локально
     * MySQL, и json-условия у них расходятся. Три флажка одинаково работают везде.
     *
     * Ни одной отметки и все три отметки означают одно и то же — полное
     * обслуживание, никакого сужения. Поэтому значение по умолчанию (все выключены)
     * повторяет нынешнее поведение, и отдельным типом «Полное обслуживание» не
     * заводится: это просто все три отметки сразу.
     *
     * Старую колонку service_type не трогаем и больше не читаем. Значения в ней
     * копились, пока поле ни на что не влияло, доверять им нельзя, а удалять
     * прямо сейчас незачем: к ней никто не обращается. Уберём отдельной уборкой,
     * когда сужение отработает.
     */
    private array $columns = [
        'serves_accounting',
        'serves_tax',
        'serves_payroll',
    ];

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $after = 'service_type';
            foreach ($this->columns as $col) {
                $table->boolean($col)->default(false)->after($after);
                $after = $col;
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
