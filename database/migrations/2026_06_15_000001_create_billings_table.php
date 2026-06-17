<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $now = now();
        DB::table('billings')->insert(array_map(fn($name) => [
            'name' => $name, 'created_at' => $now, 'updated_at' => $now,
        ], [
            'Входит в абонентку',
            'Считается по количеству',
            'Доп.услуга',
            'Не тарифицируется',
        ]));
    }

    public function down(): void { Schema::dropIfExists('billings'); }
};
