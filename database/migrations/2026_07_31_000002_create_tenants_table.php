<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аккаунты — бухфирмы, которые пользуются системой. В UI это «Аккаунт»:
 * слово «компания» уже занято, так интерфейс называет обслуживаемого клиента.
 *
 * Первая запись создаётся здесь же: это действующая фирма, к которой в следующей
 * миграции будут привязаны все существующие данные. Без неё привязывать не к чему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // trial — пробный период, active — работает, suspended — доступ закрыт
            // (не заплатили, нарушение). Данные при suspended остаются на месте.
            $table->string('status')->default('trial');
            $table->string('plan')->nullable();

            // Настройки аккаунта, которые не заслуживают отдельной колонки
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // Действующая фирма. Все данные, которые уже есть в базе, принадлежат ей.
        DB::table('tenants')->insert([
            'name'       => 'Fineco',
            'slug'       => 'fineco',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
