<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_number', 50)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('employee_number');
            $table->date('hired_at')->nullable()->after('birth_date');
            $table->date('fired_at')->nullable()->after('hired_at');
            $table->enum('employment_status', ['employed', 'fired'])->default('employed')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['employee_number', 'birth_date', 'hired_at', 'fired_at', 'employment_status']);
        });
    }
};
