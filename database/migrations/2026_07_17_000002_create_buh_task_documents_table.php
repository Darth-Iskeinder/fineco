<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько документов на задачу: отдельная таблица с morph-связью на лог плановой
 * (BuhTaskLog) или внеплановую (BuhAdhocTask) задачу. Существующие одиночные документы
 * переносятся сюда; старые колонки document_path/document_name остаются (мёртвые,
 * убрать при стабилизации) — откат тривиален.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buh_task_documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->string('path');
            $table->string('name');
            $table->timestamps();
            $table->index(['documentable_type', 'documentable_id']);
        });

        $now = now();
        $copy = function (string $sourceTable, string $morphClass) use ($now) {
            DB::table($sourceTable)
                ->whereNotNull('document_path')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($morphClass, $now) {
                    DB::table('buh_task_documents')->insert(
                        collect($rows)->map(fn ($r) => [
                            'documentable_type' => $morphClass,
                            'documentable_id'   => $r->id,
                            'path'              => $r->document_path,
                            'name'              => $r->document_name ?? basename($r->document_path),
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ])->all()
                    );
                });
        };
        $copy('buh_task_logs', \App\Models\BuhTaskLog::class);
        $copy('buh_adhoc_tasks', \App\Models\BuhAdhocTask::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('buh_task_documents');
    }
};
