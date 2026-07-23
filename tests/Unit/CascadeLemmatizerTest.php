<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nlp\CascadeLemmatizer;
use Tests\TestCase;

/**
 * H1463 — the cascade lemmatizer answers a form end-to-end (the Q3-2026 KPI gate):
 * a known DCS form → lemma + answering stage; gibberish → null. Runs against the
 * real vendored slice (resources/data/dcs_form2lemma.json), keyed with the same
 * normaliser (SanskritGlossary::normalizeKey) the feed was built with.
 */
class CascadeLemmatizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CascadeLemmatizer::flush();
    }

    public function test_known_dcs_form_resolves_to_lemma_at_the_dcs_stage(): void
    {
        $result = (new CascadeLemmatizer)->lemmatize('rājā');

        $this->assertNotNull($result);
        $this->assertSame('rājan', $result['lemma']);
        $this->assertSame('dcs', $result['stage']);   // stage 1 answered
        $this->assertSame('NOUN', $result['pos']);
        $this->assertSame(1.0, $result['confidence']);
    }

    public function test_a_second_known_form_also_resolves(): void
    {
        $result = (new CascadeLemmatizer)->lemmatize('uvāca');

        $this->assertNotNull($result);
        $this->assertSame('vac', $result['lemma']);
        $this->assertSame('dcs', $result['stage']);
    }

    public function test_gibberish_returns_null(): void
    {
        $this->assertNull((new CascadeLemmatizer)->lemmatize('xqzwbbbqx'));
    }

    public function test_empty_or_whitespace_returns_null(): void
    {
        $lemmatizer = new CascadeLemmatizer;
        $this->assertNull($lemmatizer->lemmatize(''));
        $this->assertNull($lemmatizer->lemmatize('   '));
    }

    public function test_lookup_is_normaliser_consistent_case_and_whitespace_fold(): void
    {
        $lemmatizer = new CascadeLemmatizer;
        // Case + surrounding whitespace must fold to the same key the feed uses
        // (NFC + trim + lower), or every real-world lookup would miss.
        $canonical = $lemmatizer->lemmatize('rājā');
        $this->assertNotNull($canonical);
        $this->assertSame($canonical['lemma'], $lemmatizer->lemmatize('  RĀJĀ  ')['lemma']);
    }

    public function test_cascade_order_is_dcs_first_and_a_hit_short_circuits(): void
    {
        // In v0, vidyut/heritage are stubbed to no-match, so any real hit must come
        // from stage 1 (dcs). This pins the ordering contract: the answering stage
        // for a resolvable form is never a later stage.
        $result = (new CascadeLemmatizer)->lemmatize('gataḥ');

        $this->assertNotNull($result);
        $this->assertSame('gam', $result['lemma']);
        $this->assertSame('dcs', $result['stage']);
        $this->assertContains($result['stage'], ['dcs', 'vidyut', 'heritage']);
    }

    public function test_result_always_carries_a_nullable_pos_key(): void
    {
        $result = (new CascadeLemmatizer)->lemmatize('rājā');
        $this->assertArrayHasKey('pos', $result);
    }
}
