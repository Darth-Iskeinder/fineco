<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $now = now();
        $names = [
            'Банк', 'Платежи', 'Заявки на платежи', 'Касса / ККМ', 'Первичка',
            'Авансовые отчеты', 'Архив', 'ЭСФ', 'Реестр ЭСФ', 'ЭСФ маркетплейсы',
            'Запрет изменений', 'Закрытие месяца', 'Обмен базами', 'ОСВ', 'Архив базы',
            'Зарплата', 'Отчет 161', 'Реестр работников', 'ИП / полис', 'Налоговая база',
            'Оплата налогов', 'НДС', 'НСП', 'Налог на прибыль', 'Единый налог',
            'НДС налоговый агент', 'Нерезиденты', 'Имущественные налоги', 'Сводная карточка',
            'Контроль отчетов', 'ПВТ', 'ПКИ', 'Категория НП', 'Таможня', 'Косвенные налоги',
            'Заявление о ввозе', 'ГТД', 'Экспорт', 'ГосАлко', 'Льготы', 'Кабинеты', 'Учет',
            'Сверка', 'Госфинадзор', 'Финотчетность', 'Производство', 'Материалы', 'Остатки',
            'Персональные данные', 'Инвентаризация', 'Налоговое планирование', 'Экспресс-аудит',
            'Квартальный аудит', 'Индивидуально', 'Счет / АВР',
        ];
        DB::table('service_groups')->insert(array_map(
            fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            $names,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('service_groups');
    }
};
