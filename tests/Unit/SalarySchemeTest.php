<?php

namespace Tests\Unit;

use App\Enums\SalaryScheme;
use App\Services\TeacherSalaryService;
use Tests\TestCase;

class SalarySchemeTest extends TestCase
{
    /** @test */
    public function percent_schemes_are_exactly_percent_and_percent_per_block(): void
    {
        $this->assertSame(['percent', 'percent_per_block'], SalaryScheme::percentValues());
        $this->assertTrue(SalaryScheme::Percent->isPercent());
        $this->assertTrue(SalaryScheme::PercentPerBlock->isPercent());
        $this->assertFalse(SalaryScheme::FixPerBlock->isPercent());
    }

    /** @test */
    public function fixed_schemes_are_the_three_fix_variants(): void
    {
        $this->assertSame(['fix_per_student', 'fix_total', 'fix_per_block'], SalaryScheme::fixedValues());
        $this->assertTrue(SalaryScheme::FixPerStudent->isFixed());
        $this->assertTrue(SalaryScheme::FixTotal->isFixed());
        $this->assertTrue(SalaryScheme::FixPerBlock->isFixed());
        $this->assertFalse(SalaryScheme::Percent->isFixed());
    }

    /**
     * @test
     *
     * @dataProvider rawTypeProvider
     */
    public function raw_string_helpers_match_the_old_inline_checks(?string $type, bool $isPercent, bool $isFixed): void
    {
        // Прежние инлайн-проверки: in_array($type, ['percent','percent_per_block']) и
        // in_array($type, ['fix_per_block','fix_total','fix_per_student']). Хелперы
        // должны совпадать с ними, включая null/неизвестное → false.
        $this->assertSame($isPercent, SalaryScheme::isPercentType($type));
        $this->assertSame($isFixed, SalaryScheme::isFixedType($type));
    }

    public static function rawTypeProvider(): array
    {
        return [
            'percent' => ['percent', true, false],
            'percent_per_block' => ['percent_per_block', true, false],
            'fix_per_student' => ['fix_per_student', false, true],
            'fix_total' => ['fix_total', false, true],
            'fix_per_block' => ['fix_per_block', false, true],
            'unknown' => ['weird', false, false],
            'empty' => ['', false, false],
            'null' => [null, false, false],
        ];
    }

    /** @test */
    public function service_labels_delegate_to_the_enum_and_keep_the_dropdown_order(): void
    {
        $this->assertSame(SalaryScheme::labels(), TeacherSalaryService::salaryTypeLabels());
        $this->assertSame('% от выручки', TeacherSalaryService::salaryTypeLabel('percent'));
        $this->assertSame('Фикс за блок', SalaryScheme::FixPerBlock->label());
        // Неизвестный тип — прежний фоллбэк (сам ключ, иначе тире).
        $this->assertSame('weird', TeacherSalaryService::salaryTypeLabel('weird'));
        $this->assertSame('—', TeacherSalaryService::salaryTypeLabel(null));
    }
}
