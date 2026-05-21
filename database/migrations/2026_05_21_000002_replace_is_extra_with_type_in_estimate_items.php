<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->string('type', 20)->default('recurring')->after('is_extra');
        });

        DB::table('estimate_items')->where('is_extra', true)->update(['type' => 'one_time']);
        DB::table('estimate_items')->where('is_extra', false)->update(['type' => 'recurring']);

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('is_extra');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->boolean('is_extra')->default(false)->after('service_id');
        });

        DB::table('estimate_items')->where('type', 'one_time')->update(['is_extra' => true]);
        DB::table('estimate_items')->where('type', 'recurring')->update(['is_extra' => false]);

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
