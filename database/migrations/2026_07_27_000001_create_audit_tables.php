<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль «Аудит» — независимый ретроспективный контроль качества закрытой работы.
 * Аудитор берёт клиента за период и проверяет: (а) фактически закрытые БП
 * (buh_task_logs) — вердикт по каждой задаче, (б) чек-лист контрольных точек,
 * скопированный из стандарта в момент создания аудита.
 *
 * Чек-лист копируется, а не ссылается на шаблон: правки внутри аудита не должны
 * менять стандарт, а изменение стандарта — задним числом менять завершённые аудиты.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Стандарт чек-листа: шаблон + его пункты
        Schema::create('audit_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('audit_checklist_templates')->cascadeOnDelete();
            $table->string('section');                 // раздел учёта: Банк, Касса, ОСВ…
            $table->string('account')->nullable();     // счёт 1С: 1210/1220, 1250…
            $table->text('point');                     // что проверяем (контрольная точка)
            $table->text('how')->nullable();           // как проверить / источник
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['template_id', 'sort_order']);
        });

        // Сам аудит: один клиент за один период
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auditor_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('audit_checklist_templates')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('in_progress'); // draft | in_progress | completed
            $table->unsignedTinyInteger('score')->nullable(); // 0..100, считается при завершении
            $table->text('summary')->nullable();              // резюме аудитора
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'period_start']);
            $table->index('status');
        });

        // Вердикт аудитора по конкретной закрытой задаче
        Schema::create('audit_task_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            // nullOnDelete + снимок названия: пересохранение сметы может удалить лог,
            // но завершённый аудит должен остаться читаемым.
            $table->foreignId('buh_task_log_id')->nullable()->constrained('buh_task_logs')->nullOnDelete();
            $table->string('task_name');                      // снимок названия БП
            $table->string('section')->nullable();            // снимок участка (services.service_group)
            $table->string('verdict');                        // ok | finding
            $table->string('severity')->nullable();           // critical | major | minor (только для finding)
            $table->text('comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['audit_id', 'buh_task_log_id']);
        });

        // Чек-лист внутри аудита (копия стандарта, дальше живёт своей жизнью)
        Schema::create('audit_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('section');
            $table->string('account')->nullable();
            $table->text('point')->nullable();
            $table->text('how')->nullable();
            $table->string('status')->nullable();      // null = не проверено | ok | err | ask | na
            $table->string('doc_link')->nullable();    // ссылка на документ (Google Диск и т.п.)
            $table->text('comment')->nullable();       // расхождение / комментарий аудитора
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['audit_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_checklist_items');
        Schema::dropIfExists('audit_task_reviews');
        Schema::dropIfExists('audits');
        Schema::dropIfExists('audit_checklist_template_items');
        Schema::dropIfExists('audit_checklist_templates');
    }
};
