<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('sphere')->nullable()->after('description');
            $table->string('service_group')->nullable()->after('sphere');
            $table->string('business_process')->nullable()->after('service_group');
            $table->string('category')->nullable()->after('business_process');
            $table->unsignedInteger('deadline_days')->nullable()->after('due_day');
            $table->unsignedInteger('execution_minutes')->nullable()->after('deadline_days');
            $table->string('closing_rule')->nullable()->after('execution_minutes');
            $table->string('check_type')->nullable()->after('closing_rule');
            $table->string('billing')->nullable()->after('check_type');
            $table->text('comment')->nullable()->after('billing');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'sphere', 'service_group', 'business_process', 'category',
                'deadline_days', 'execution_minutes', 'closing_rule',
                'check_type', 'billing', 'comment',
            ]);
        });
    }
};
