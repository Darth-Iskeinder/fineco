<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // =============================================
            // ОСНОВНАЯ ИНФОРМАЦИЯ
            // =============================================
            $table->string('director_inn', 14)->nullable()->after('inn'); // ИНН руководителя
            $table->string('activity_type', 500)->nullable()->after('director_inn'); // Основной вид деятельности
            $table->string('tax_office_code', 10)->nullable()->after('activity_type'); // Код НО

            // =============================================
            // НАЛОГОВЫЕ ДАННЫЕ
            // =============================================
            $table->string('accounting_method', 20)->nullable()->after('tax_system_id'); // Метод учета ДиР: cash, accrual
            $table->string('taxpayer_category', 20)->nullable()->after('accounting_method'); // Категория: small, medium, large

            // =============================================
            // ДОГОВОР И ОБСЛУЖИВАНИЕ
            // =============================================
            $table->string('contract_with', 255)->nullable()->after('price'); // С кем составлен договор
            $table->date('service_start_date')->nullable()->after('contract_with'); // Дата начала обслуживания
            $table->date('service_end_date')->nullable()->after('service_start_date'); // Дата завершения обслуживания
            $table->string('contract_url', 500)->nullable()->after('service_end_date'); // Ссылка на договор
            $table->json('founding_docs_urls')->nullable()->after('contract_url'); // Ссылки на учред доки (массив)
            $table->string('requisites_url', 500)->nullable()->after('founding_docs_urls'); // Ссылка на реквизиты

            // =============================================
            // ДОВЕРЕННОСТЬ
            // =============================================
            $table->string('power_of_attorney_name', 255)->nullable()->after('requisites_url'); // Доверенность на имя
            $table->date('power_of_attorney_expires')->nullable()->after('power_of_attorney_name'); // Срок доверенности

            // =============================================
            // ЭЦП И ДОСТУПЫ (шифруются в модели)
            // =============================================
            $table->text('eds_password')->nullable()->after('power_of_attorney_expires'); // Пароль ЭЦП/Тундук ЭСИ
            $table->date('eds_expires')->nullable()->after('eds_password'); // Срок действия ЭЦП
            $table->text('cabinet_credentials')->nullable()->after('eds_expires'); // Логин/пароль от кабинета {login, password}
            $table->text('esf_user_credentials')->nullable()->after('cabinet_credentials'); // Доп пользователь ЭСФ {login, password}
            $table->text('ettn_user_credentials')->nullable()->after('esf_user_credentials'); // Доп пользователь ЭТТН {login, password}

            // =============================================
            // ИТС (1С)
            // =============================================
            $table->boolean('its_enabled')->default(false)->after('ettn_user_credentials'); // Обслуживание ИТС
            $table->string('connection_type', 20)->nullable()->after('its_enabled'); // Способ подключения: local, cloud, rdp
            $table->text('its_credentials')->nullable()->after('connection_type'); // Логин/пароль от ИТС
            $table->string('database_path', 500)->nullable()->after('its_credentials'); // Путь к базе
            $table->text('onec_connect_credentials')->nullable()->after('database_path'); // Логин/пароль от 1С Коннект
            $table->string('its_contact', 255)->nullable()->after('onec_connect_credentials'); // Контактное лицо ИТС

            // =============================================
            // ИНТЕРНЕТ-БАНКИНГ (до 3 банков)
            // =============================================
            $table->text('bank_credentials')->nullable()->after('its_contact'); // JSON массив [{bank, login, password}, ...]

            // =============================================
            // ИНДЕКСЫ
            // =============================================
            $table->index('service_start_date');
            $table->index('eds_expires');
            $table->index('its_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Удаляем индексы
            $table->dropIndex(['service_start_date']);
            $table->dropIndex(['eds_expires']);
            $table->dropIndex(['its_enabled']);

            // Удаляем колонки
            $table->dropColumn([
                // Основная информация
                'director_inn',
                'activity_type',
                'tax_office_code',
                // Налоговые данные
                'accounting_method',
                'taxpayer_category',
                // Договор
                'contract_with',
                'service_start_date',
                'service_end_date',
                'contract_url',
                'founding_docs_urls',
                'requisites_url',
                // Доверенность
                'power_of_attorney_name',
                'power_of_attorney_expires',
                // ЭЦП
                'eds_password',
                'eds_expires',
                'cabinet_credentials',
                'esf_user_credentials',
                'ettn_user_credentials',
                // ИТС
                'its_enabled',
                'connection_type',
                'its_credentials',
                'database_path',
                'onec_connect_credentials',
                'its_contact',
                // Банки
                'bank_credentials',
            ]);
        });
    }
};
