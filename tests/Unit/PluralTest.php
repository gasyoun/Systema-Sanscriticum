<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Plural;
use PHPUnit\Framework\TestCase;

class PluralTest extends TestCase
{
    /** @test */
    public function picks_correct_russian_form_for_dney(): void
    {
        $form = fn (int $n) => Plural::ru($n, 'день', 'дня', 'дней');

        $this->assertSame('день', $form(1));
        $this->assertSame('дня', $form(2));
        $this->assertSame('дня', $form(3));
        $this->assertSame('дней', $form(5));
        $this->assertSame('дней', $form(11));   // 11–14 → many
        $this->assertSame('дней', $form(14));
        $this->assertSame('день', $form(21));
        $this->assertSame('дня', $form(22));
        $this->assertSame('дней', $form(0));
        $this->assertSame('дней', $form(100));
        $this->assertSame('день', $form(101));
    }

    /** @test */
    public function picks_correct_form_for_chelovek_and_mest(): void
    {
        $this->assertSame('человек', Plural::ru(37, 'человек', 'человека', 'человек'));
        $this->assertSame('человека', Plural::ru(42, 'человек', 'человека', 'человек'));
        $this->assertSame('мест', Plural::ru(10, 'место', 'места', 'мест'));
        $this->assertSame('места', Plural::ru(3, 'место', 'места', 'мест'));
        $this->assertSame('место', Plural::ru(1, 'место', 'места', 'мест'));
    }
}
