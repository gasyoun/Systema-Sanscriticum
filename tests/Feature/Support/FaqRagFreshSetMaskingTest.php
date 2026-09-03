<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\FaqCorpusParser;
use Tests\TestCase;

/**
 * H4001 — fresh eval-набор: PII-гейты над САМИМ committed-фикстуром
 * (маскирование должно быть доказуемо, а не обещано), независимость от
 * 80Q-набора и существование всех expected chunk_id в корпусе.
 */
class FaqRagFreshSetMaskingTest extends TestCase
{
    public function test_fresh_fixture_has_no_pii_and_is_independent(): void
    {
        $path = base_path('tests/fixtures/faq_rag_eval_fresh.json');
        $this->assertFileExists($path);

        /** @var array{items: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $fixture['items'];
        $this->assertNotEmpty($items, 'fresh set must not be empty');
        $this->assertGreaterThanOrEqual(20, count($items), 'fresh set shrinked below the committed ratchet');

        foreach ($items as $item) {
            $question = (string) $item['question'];

            $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/u', $question, 'raw e-mail leaked');
            $this->assertDoesNotMatchRegularExpression('/(?:\+?\d[\d\s().-]{8,}\d)/u', $question, 'raw phone leaked');
            $this->assertDoesNotMatchRegularExpression('/(?<![\w\/])@[A-Za-z][A-Za-z0-9_]{3,}/u', $question, 'raw handle leaked');
            $this->assertDoesNotMatchRegularExpression('/https?:\/\/\S+/u', $question, 'raw link leaked');
            $this->assertDoesNotMatchRegularExpression('/(?<!\d)\d{7,}(?!\d)/u', $question, 'raw long id leaked');
            $this->assertDoesNotMatchRegularExpression('/t\.me\//u', $question, 'telegram handle leaked');

            $this->assertNotEmpty($item['expected_chunk_ids'], 'labelled item must have at least one expected chunk');
        }
    }

    public function test_fresh_set_is_disjoint_from_the_80q_set(): void
    {
        $old = json_decode((string) file_get_contents(base_path('tests/fixtures/faq_rag_eval.json')), true, 512, JSON_THROW_ON_ERROR);
        $fresh = json_decode((string) file_get_contents(base_path('tests/fixtures/faq_rag_eval_fresh.json')), true, 512, JSON_THROW_ON_ERROR);

        $oldKeys = [];
        foreach ($old['items'] as $item) {
            $oldKeys[$this->dedupeKey((string) $item['question'])] = true;
        }

        foreach ($fresh['items'] as $item) {
            $this->assertArrayNotHasKey(
                $this->dedupeKey((string) $item['question']),
                $oldKeys,
                'fresh set overlaps the 80Q set: independence defeated',
            );
        }
    }

    public function test_every_expected_chunk_id_exists_in_corpus(): void
    {
        $fresh = json_decode((string) file_get_contents(base_path('tests/fixtures/faq_rag_eval_fresh.json')), true, 512, JSON_THROW_ON_ERROR);

        $known = [];
        foreach (app(FaqCorpusParser::class)->chunks() as $chunk) {
            $known[$chunk->chunkId] = true;
        }

        foreach ($fresh['items'] as $item) {
            foreach ((array) $item['expected_chunk_ids'] as $eid) {
                $this->assertArrayHasKey($eid, $known, "expected chunk_id missing from corpus: {$eid}");
            }
        }
    }

    private function dedupeKey(string $text): string
    {
        $k = mb_strtolower($text, 'UTF-8');
        $k = preg_replace('/[^\p{L}\p{N}]+/u', '', $k) ?? $k;

        return mb_substr($k, 0, 120);
    }
}
