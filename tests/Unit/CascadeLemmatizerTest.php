<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nlp\CascadeLemmatizer;
use App\Services\SanskritGlossary;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H1463 — cascade lemmatizer v0 (DCS → vidyut → Heritage).
 * Executed by Grok 4.5 (grok-4.5) via xAI — Claude/Opus verify after.
 */
class CascadeLemmatizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CascadeLemmatizer::flush();
        Cache::flush();
    }

    public function test_known_dcs_form_returns_lemma_with_dcs_stage(): void
    {
        $svc = new CascadeLemmatizer;
        $hit = $svc->lemmatize('uvāca');

        $this->assertNotNull($hit);
        $this->assertSame('vac', $hit['lemma']);
        $this->assertSame('dcs', $hit['stage']);
        $this->assertGreaterThan(0, $hit['confidence']);
    }

    public function test_gibberish_returns_null(): void
    {
        $svc = new CascadeLemmatizer;
        $this->assertNull($svc->lemmatize('zzzxxyyqqnotasanskritform'));
    }

    public function test_key_normalization_round_trip_matches_glossary(): void
    {
        $form = '  UvāCa  ';
        $key = SanskritGlossary::normalizeKey($form);
        $this->assertSame('uvāca', $key);

        $svc = new CascadeLemmatizer;
        $hit = $svc->lemmatize($form);
        $this->assertNotNull($hit);
        $this->assertSame('vac', $hit['lemma']);
        $this->assertSame('dcs', $hit['stage']);
    }

    public function test_cascade_order_prefers_dcs_over_later_stages(): void
    {
        $tmpVidyut = tempnam(sys_get_temp_dir(), 'vidyut');
        file_put_contents($tmpVidyut, json_encode([
            'entries' => [
                'uvāca' => ['lemma' => 'WRONG_VIDYUT', 'pos' => ''],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $svc = new CascadeLemmatizer(vidyutPath: $tmpVidyut);
            $hit = $svc->lemmatize('uvāca');
            $this->assertNotNull($hit);
            $this->assertSame('vac', $hit['lemma']);
            $this->assertSame('dcs', $hit['stage']);
        } finally {
            @unlink($tmpVidyut);
        }
    }

    public function test_vidyut_stage_answers_when_dcs_misses(): void
    {
        $tmpDcs = tempnam(sys_get_temp_dir(), 'dcs');
        $tmpVidyut = tempnam(sys_get_temp_dir(), 'vidyut');
        file_put_contents($tmpDcs, json_encode(['entries' => []], JSON_UNESCAPED_UNICODE));
        file_put_contents($tmpVidyut, json_encode([
            'entries' => [
                'onlyvidyut' => ['lemma' => 'only', 'pos' => 'NOUN'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        CascadeLemmatizer::flush();
        Cache::flush();

        try {
            $svc = new CascadeLemmatizer(dcsPath: $tmpDcs, vidyutPath: $tmpVidyut);
            $hit = $svc->lemmatize('onlyvidyut');
            $this->assertNotNull($hit);
            $this->assertSame('only', $hit['lemma']);
            $this->assertSame('vidyut', $hit['stage']);
        } finally {
            @unlink($tmpDcs);
            @unlink($tmpVidyut);
        }
    }
}
