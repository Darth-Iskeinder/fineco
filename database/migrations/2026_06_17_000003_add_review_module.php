<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')->where('name', 'settings')->update(['sort_order' => 6]);

        DB::table('modules')->insert([
            'name' => 'review',
            'display_name' => 'Проверка',
            'icon' => 'clipboard-document-check',
            'route' => 'review',
            'sort_order' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('modules')->where('name', 'review')->delete();
        DB::table('modules')->where('name', 'settings')->update(['sort_order' => 5]);
    }
};
