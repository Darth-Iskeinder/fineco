<?php

namespace Tests\Unit;

use App\Rules\Inn;
use PHPUnit\Framework\TestCase;

/**
 * Правило ИНН: ровно 14 цифр, не заглушка, и понятная причина, если ИНН испорчен.
 * Примеры взяты из КЛВ и Fineco, 13.10.2026.
 */
class InnRuleTest extends TestCase
{
    private function error(string $value, bool $fromFile = false): ?string
    {
        $error = null;

        (new Inn($fromFile))->validate('inn', $value, function (string $message) use (&$error) {
            $error = $message;
        });

        return $error;
    }

    public function test_accepts_organization_and_person(): void
    {
        $this->assertNull($this->error('01512202310049'));
        $this->assertNull($this->error('00302202310328'));
        $this->assertNull($this->error('21605200040024'));
    }

    /** Excel отбросил ведущие нули: один (13 цифр) или два, если день меньше десятого (12 цифр). */
    public function test_names_lost_leading_zeros(): void
    {
        $this->assertStringContainsString('потерялся ноль в начале', $this->error('1512202310049'));
        $this->assertStringContainsString('В ИНН 12 цифр', $this->error('302202310328'));
        $this->assertStringNotContainsString('Excel', $this->error('1512202310049'));
        $this->assertStringContainsString('Excel', $this->error('1512202310049', fromFile: true));
    }

    /** ИНН, обрезанный сзади старой колонкой в 12 знаков: нулём не исправить, просто «14 цифр». */
    public function test_truncated_tail_is_just_too_short(): void
    {
        $this->assertSame('ИНН состоит из 14 цифр', $this->error('021012025102'));
        $this->assertSame('ИНН состоит из 14 цифр', $this->error('0300120181029'));
        $this->assertSame('ИНН состоит из 14 цифр', $this->error('016082024107123'));
    }

    public function test_rejects_placeholder(): void
    {
        $this->assertStringContainsString('не настоящий ИНН', $this->error('00000000000003'));
    }

    public function test_rejects_spaces_and_letters(): void
    {
        $this->assertStringContainsString('одними цифрами', $this->error('0151 2202310049'));
        $this->assertStringContainsString('одними цифрами', $this->error('ИНН01512202310'));
    }
}
