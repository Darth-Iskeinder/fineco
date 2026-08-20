<?php

namespace Tests\Unit;

use App\Support\KgPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Разбор кыргызского номера.
 *
 * Форма отдаёт девять национальных цифр, но в поле попадает и вставка из
 * мессенджера. Это тот же номер, поэтому лишнее снимаем, а не отбиваем ошибкой.
 */
class KgPhoneTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function numbers(): array
    {
        return [
            'как в форме'          => ['705 664 746', '705664746'],
            'слитно'               => ['705664746', '705664746'],
            'с кодом страны'       => ['+996 705 664 746', '705664746'],
            'код без плюса'        => ['996705664746', '705664746'],
            'набор внутри страны'  => ['0705664746', '705664746'],
            'из карточки'          => ['+996 (705) 664-746', '705664746'],
            // У операторов есть коды 99X: девять цифр, начинающихся с 996, —
            // это номер целиком, и трогать его нельзя.
            'номер оператора 996'  => ['996 123 456', '996123456'],
            'он же с кодом страны' => ['+996 996 123 456', '996123456'],
            'пусто'                => ['', null],
            'одни знаки'           => ['+ ()-', null],
            'ничего'               => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_national_digits_are_extracted(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, KgPhone::digits($raw));
    }

    public function test_stored_shape_matches_the_employee_card_mask(): void
    {
        $this->assertSame('+996 (705) 664-746', KgPhone::format('705664746'));
    }
}
