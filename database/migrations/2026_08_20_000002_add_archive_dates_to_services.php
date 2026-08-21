<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Рабочий период бизнес-процесса: с какого месяца ведём и по какой.
     *
     * Тот же приём, что у клиента с обслуживанием. Архивация — это не «выключить»,
     * а «перестать вести с такого-то месяца»: незакрытые задачи внутри периода
     * остаются (их всё равно сдавать), новые за его пределами не заводятся.
     *
     * Обе даты пусты у всех существующих БП — то есть период не ограничен ни с
     * одной стороны, и поведение действующего каталога не меняется.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Последний день, за который БП ещё положены задачи (конец месяца архивации).
            $table->date('archived_at')->nullable()->after('is_active');
            // Первый день, с которого БП снова ведут (начало месяца после возврата).
            // Нужен отдельно от archived_at: снятие архива задним числом иначе
            // насыпало бы просрочку за всё время простоя.
            $table->date('active_from')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'active_from']);
        });
    }
};
