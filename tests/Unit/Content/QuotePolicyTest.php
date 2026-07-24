<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\QuotePolicy;
use InvalidArgumentException;
use Tests\TestCase;

class QuotePolicyTest extends TestCase
{
    public function test_null_and_empty_are_ok(): void
    {
        QuotePolicy::assertPublicQuote(null);
        QuotePolicy::assertPublicQuote('');
        QuotePolicy::assertPublicQuote('   ');
        $this->assertTrue(true);
    }

    public function test_one_sentence_is_ok(): void
    {
        QuotePolicy::assertPublicQuote('Санскрит — древний язык.');
        $this->assertTrue(true);
    }

    public function test_two_sentences_is_ok(): void
    {
        QuotePolicy::assertPublicQuote('Санскрит — древний язык. Он до сих пор изучается.');
        $this->assertTrue(true);
    }

    public function test_three_sentences_hard_fails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuotePolicy::assertPublicQuote('Первое. Второе. Третье.');
    }

    public function test_cyrillic_question_and_exclamation_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuotePolicy::assertPublicQuote('Кто это придумал? Зачем?! И почему так сложно?');
    }
}
