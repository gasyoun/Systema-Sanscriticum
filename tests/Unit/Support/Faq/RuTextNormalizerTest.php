<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Faq;

use App\Services\Support\Faq\RuTextNormalizer;
use PHPUnit\Framework\TestCase;

/** H3766 B3 — пины лёгкой русской нормализации под BM25. */
class RuTextNormalizerTest extends TestCase
{
    private RuTextNormalizer $ru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ru = new RuTextNormalizer;
    }

    public function test_fold_collapses_yo_and_case(): void
    {
        $this->assertSame('зачет', $this->ru->fold('Зачёт'));
        $this->assertSame('еще', $this->ru->fold('ЕЩЁ'));
    }

    /**
     * Ради этого весь стеммер: студент пишет «оплатить», в faq.md стоит «Оплата».
     */
    public function test_payment_word_forms_share_one_stem(): void
    {
        $stems = array_map(fn (string $w): string => $this->ru->stem($w), ['оплата', 'оплатить', 'оплаты', 'оплате']);
        $this->assertSame(['оплат', 'оплат', 'оплат', 'оплат'], $stems);
    }

    public function test_recording_word_forms_share_one_stem(): void
    {
        $stems = array_map(fn (string $w): string => $this->ru->stem($w), ['запись', 'записи', 'записей']);
        $this->assertSame(['запис', 'запис', 'запис'], $stems);
    }

    /**
     * Регрессия: «записаться» (на курс) не должно схлопываться в «запись»
     * (видеозапись) — второй проход стеммера это делал и стоил −6 п.п.
     * recall@5 по категории B на tests/fixtures/faq_rag_eval.json.
     */
    public function test_reflexive_verb_does_not_collapse_into_the_noun(): void
    {
        $this->assertSame('записа', $this->ru->stem('записаться'));
        $this->assertNotSame($this->ru->stem('запись'), $this->ru->stem('записаться'));
    }

    public function test_short_tokens_are_left_alone(): void
    {
        $this->assertSame('зум', $this->ru->stem('зум'));
        $this->assertSame('дз', $this->ru->stem('дз'));
    }

    public function test_expand_query_adds_synonyms_and_keeps_originals(): void
    {
        $expanded = $this->ru->expandQuery(['зум', 'ссылк']);
        $this->assertContains('зум', $expanded);
        $this->assertContains('zoom', $expanded);
        $this->assertContains('ссылк', $expanded);
    }

    public function test_expand_query_is_idempotent_on_unknown_terms(): void
    {
        $this->assertSame(['санскрит', 'курс'], $this->ru->expandQuery(['санскрит', 'курс']));
    }
}
