<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наблюдение за прогоном автоаудита (этап 2а, 08.10.2026).
 *
 * Позже сверки может делать ИИ, и каждая станет платной. Тогда ночь не должна перепроверять
 * тех, у кого ничего не поменялось. Прежде чем так делать, смотрим со стороны: прогон
 * по-прежнему проверяет всех, а рядом пишет, кого бы он пропустил и не ошибся бы при этом.
 * На поведение прогона и страниц эти таблицы не влияют.
 *
 * auto_audit_document_reads: что узнали из файла, прочитав его. Главное период: по нему
 *     видно, какие месяцы задел новый или заменённый файл.
 * auto_audit_snapshots: снимок того, на чём построены сверки клиента, после последней
 *     проверки. Целиком по клиенту и отдельно по каждому месяцу.
 * auto_audit_watch_runs: итог одного прогона: сколько клиентов, сколько из них нужно было
 *     проверять, сколько вышло бы сверок и сколько тревог.
 * auto_audit_watch_clients: клиенты прогона, которых нужно было проверять, и тревоги.
 *     Тревога значит, что снимок не изменился, а итог сверки изменился: снимок что-то
 *     упустил, пропускать по нему пока нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_audit_document_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Без внешнего ключа: файл удаляют, а запись о прочитанном нужна до следующего
            // прогона, чтобы понять, какой месяц задело удаление.
            $table->unsignedBigInteger('document_id')->unique();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('side', 8);
            // Файл заменяют и на месте, с тем же id: тогда прочитанное больше не годится.
            $table->string('path');
            // Пусто: форму не опознали (скан, чужой бланк, битый файл).
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 16);
            $table->string('inn', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('auto_audit_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->string('hash', 40);
            // Части, общие для всех месяцев: карточка клиента, настройки фирмы, код проверок.
            $table->json('parts');
            // Месяц «ГГГГ-ММ» => отпечаток задач и файлов, которые к нему относятся.
            $table->json('months');
            $table->timestamps();
        });

        Schema::create('auto_audit_watch_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('trigger', 16);              // night | command
            $table->decimal('seconds', 8, 1);
            $table->unsignedInteger('clients');
            $table->unsignedInteger('clients_needed');
            // Сверка = клиент × период × проверка (№1-№7).
            $table->unsignedInteger('checks');
            $table->unsignedInteger('checks_by_client');   // если перепроверять клиента целиком
            $table->unsignedInteger('checks_by_month');    // если только задетые месяцы
            $table->unsignedInteger('alarms');
            $table->unsignedInteger('alarms_by_month');
            $table->string('code_hash', 40);
            $table->timestamps();
        });

        Schema::create('auto_audit_watch_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('run_id')->constrained('auto_audit_watch_runs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('reason', 16);               // new | code | settings | client | tasks | same
            $table->json('months');                     // задетые месяцы
            $table->unsignedInteger('checks');
            $table->unsignedInteger('checks_by_month');
            // Ключи строк, у которых итог стал другим, появился или ушёл.
            $table->json('changed');
            $table->boolean('alarm')->default(false);
            $table->boolean('alarm_by_month')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_audit_watch_clients');
        Schema::dropIfExists('auto_audit_watch_runs');
        Schema::dropIfExists('auto_audit_snapshots');
        Schema::dropIfExists('auto_audit_document_reads');
    }
};
