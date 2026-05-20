<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Базовые виды деятельности
        DB::table('activity_types')->insert([
            ['name' => 'Торговля', 'code' => 'trade', 'description' => 'Оптовая и розничная торговля', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Услуги', 'code' => 'services', 'description' => 'Оказание услуг', 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Производство', 'code' => 'manufacturing', 'description' => 'Производственная деятельность', 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Строительство', 'code' => 'construction', 'description' => 'Строительные работы', 'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'IT', 'code' => 'it', 'description' => 'Информационные технологии', 'is_active' => true, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Сельское хозяйство', 'code' => 'agriculture', 'description' => 'Сельскохозяйственная деятельность', 'is_active' => true, 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Транспорт', 'code' => 'transport', 'description' => 'Транспортные услуги и логистика', 'is_active' => true, 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Общепит', 'code' => 'catering', 'description' => 'Общественное питание', 'is_active' => true, 'sort_order' => 8, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_types');
    }
};
