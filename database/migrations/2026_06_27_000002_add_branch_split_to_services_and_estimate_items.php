<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Новый флаг: БП дублируется по налоговым органам клиента (основной + филиалы).
            $table->boolean('splits_by_branch')->default(false)->after('is_loans');
        });

        // Старая метка-фильтр «Филиалы» (is_branches) убрана из особых условий —
        // её заменяет splits_by_branch с другим поведением (размножение, а не фильтр).
        if (Schema::hasColumn('services', 'is_branches')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('is_branches');
            });
        }

        Schema::table('estimate_items', function (Blueprint $table) {
            // Для филиальных копий БП: к какому НО относится строка и подпись для отображения.
            $table->string('tax_office_code', 10)->nullable()->after('service_id');
            $table->string('branch_label')->nullable()->after('tax_office_code');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('splits_by_branch');
            $table->boolean('is_branches')->default(false);
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn(['tax_office_code', 'branch_label']);
        });
    }
};
