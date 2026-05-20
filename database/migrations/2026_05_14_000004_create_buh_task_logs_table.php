<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buh_task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_item_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->string('status')->default('pending'); // pending|running|paused|completed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resumed_at')->nullable(); // последнее возобновление
            $table->integer('paused_seconds')->default(0); // накопленное время паузы
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'client_id', 'estimate_item_id', 'year', 'month'], 'buh_task_logs_unique');
            $table->index(['employee_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buh_task_logs');
    }
};
