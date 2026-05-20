<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buh_adhoc_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('cost', 10, 2)->default(0);
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->string('status')->default('pending'); // pending|running|paused|completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->integer('paused_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'year', 'month']);
            $table->index(['client_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buh_adhoc_tasks');
    }
};
