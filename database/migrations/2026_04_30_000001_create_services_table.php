<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->string('periodicity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_tariff', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_id', 'tariff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tariff');
        Schema::dropIfExists('services');
    }
};
