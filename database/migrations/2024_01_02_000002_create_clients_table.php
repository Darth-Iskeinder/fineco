<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('inn', 12)->unique();
            $table->foreignId('tax_system_id')->nullable()->constrained('tax_systems')->nullOnDelete();
            $table->string('service_type')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('tax_system_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
