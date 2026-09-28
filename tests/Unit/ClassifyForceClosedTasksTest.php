<?php

namespace Tests\Unit;

use App\Console\Commands\ClassifyForceClosedTasks;
use App\Models\BuhTaskLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Разбор старых комментариев принудительного закрытия. Тексты взяты с боя Fineco
 * 28.09.2026 как есть, с опечатками и обрывками.
 */
class ClassifyForceClosedTasksTest extends TestCase
{
    public static function comments(): array
    {
        return [
            ['ежеквартально', BuhTaskLog::FORCE_QUARTERLY],
            ['ежеквартально сдает', BuhTaskLog::FORCE_QUARTERLY],
            ['квартальный отчет', BuhTaskLog::FORCE_QUARTERLY],
            ['сдает ежеквартально', BuhTaskLog::FORCE_QUARTERLY],
            ['Отчет сдается ежеквартально, в этом месяце не треб', BuhTaskLog::FORCE_QUARTERLY],
            ['Сдает отчет квартально, в этом месяце не требуется', BuhTaskLog::FORCE_QUARTERLY],
            ['единый ежеквартально сдает', BuhTaskLog::FORCE_QUARTERLY],
            ['отчет сдает ежеквартально, в этом месяце не требуется', BuhTaskLog::FORCE_QUARTERLY],
            ['квартально', BuhTaskLog::FORCE_QUARTERLY],

            ['Селлеры ВБ освобождены от сдачи и оплаты единого налога до конца 2026 года', BuhTaskLog::FORCE_EXEMPT],
            ['селлер ВБ освобожден от сдачи и оплаты единого налога', BuhTaskLog::FORCE_EXEMPT],

            ['Нет операций', BuhTaskLog::FORCE_ZERO],
            ['нулевой отчет не принимается', BuhTaskLog::FORCE_ZERO],
            ['Нулевая отчетность не допускается к подаче', BuhTaskLog::FORCE_ZERO],
            ['нул', BuhTaskLog::FORCE_ZERO],
            ['нулев', BuhTaskLog::FORCE_ZERO],
            ['нулевой не сдается', BuhTaskLog::FORCE_ZERO],
            ['Нет выручки', BuhTaskLog::FORCE_ZERO],
            ['нулевой отчет, реализации не было', BuhTaskLog::FORCE_ZERO],
            ['Нулевая отчетность', BuhTaskLog::FORCE_ZERO],
            ['не было продаж', BuhTaskLog::FORCE_ZERO],
            ['Начислений зарплаты в августе нет', BuhTaskLog::FORCE_ZERO],
            ['Нет сотрудников', BuhTaskLog::FORCE_ZERO],
            ['Нет движений', BuhTaskLog::FORCE_ZERO],

            // Филиал не сдаёт: для автоаудита это и так «не ждём», ноль не нужен.
            ['Только один район', null],
            ['1 сотрудник по Ленинскому району', null],
            // Документа не сделали, а не «он нулевой»: оборот неизвестен.
            ['База не открывается', null],
            ['плщдзкльпды', null],
            ['', null],
            [null, null],
        ];
    }

    #[DataProvider('comments')]
    public function test_guess(?string $comment, ?string $expected): void
    {
        $this->assertSame($expected, ClassifyForceClosedTasks::guess($comment));
    }
}
