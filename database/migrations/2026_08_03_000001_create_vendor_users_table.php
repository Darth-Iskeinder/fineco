<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Владельцы системы — те, кто обслуживает саму программу, а не бухгалтерию.
 *
 * Отдельная таблица, а не галочка «супер-админ» у сотрудника. Разница важная:
 * будь это галочка, администратор любой фирмы мог бы случайно поставить её
 * своему бухгалтеру, и тот увидел бы клиентов всех фирм разом. Здесь галочки
 * просто нет — попасть в этот список можно только командой в терминале.
 *
 * Колонки tenant_id тут нет и быть не должно: вендор не принадлежит ни одной
 * фирме, он снаружи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};
