<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CsvFormulaGuard;
use PHPUnit\Framework\TestCase;

/**
 * H5086 — юнит-контракт нейтрализатора formula injection (находка H5046).
 * Ячейка, начинающаяся с = + - @ TAB CR, получает ведущий апостроф;
 * обычные строки и нестроковые типы проходят без изменений.
 */
final class CsvFormulaGuardTest extends TestCase
{
    public function test_all_dangerous_first_chars_get_quote_prefix(): void
    {
        $this->assertSame("'=1+1", CsvFormulaGuard::text('=1+1'));
        $this->assertSame("'+79990000000", CsvFormulaGuard::text('+79990000000'));
        $this->assertSame("'-REF", CsvFormulaGuard::text('-REF'));
        $this->assertSame("'@SUM(1)", CsvFormulaGuard::text('@SUM(1)'));
        $this->assertSame("'\tTab", CsvFormulaGuard::text("\tTab"));
        $this->assertSame("'\rCR", CsvFormulaGuard::text("\rCR"));
    }

    public function test_benign_strings_pass_untouched(): void
    {
        $this->assertSame('Анна Чехова', CsvFormulaGuard::text('Анна Чехова'));
        $this->assertSame('anna@example.com', CsvFormulaGuard::text('anna@example.com'));
        $this->assertSame('a=b', CsvFormulaGuard::text('a=b'));
        $this->assertSame('', CsvFormulaGuard::text(''));
    }

    public function test_non_string_cells_pass_through_with_type(): void
    {
        $this->assertSame(42, CsvFormulaGuard::cell(42));
        $this->assertSame(3.14, CsvFormulaGuard::cell(3.14));
        $this->assertNull(CsvFormulaGuard::cell(null));
        $this->assertTrue(CsvFormulaGuard::cell(true));
    }

    public function test_row_maps_every_cell(): void
    {
        $row = CsvFormulaGuard::row(['=x', 'ok', 5, null, '@y']);

        $this->assertSame(["'=x", 'ok', 5, null, "'@y"], $row);
    }
}
