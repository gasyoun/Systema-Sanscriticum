<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Support\CdslLinks;
use App\Support\IastToSlp1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3762 — Cologne CDSL link-out on /slovar entry pages.
 *
 * The IAST→SLP1 expectations are the sanskrit-util golden pairs; the URL shape
 * is SHARED_CODE.md row 22 (csl-atlas cologne-links.mjs entryUrl()).
 */
class SlovarCdslLinksTest extends TestCase
{
    use RefreshDatabase;

    private function makeWord(array $word = []): DictionaryWord
    {
        $dict = Dictionary::create(['name' => 'Словарь Кочергиной', 'is_active' => true]);

        return DictionaryWord::create(array_merge([
            'dictionary_id' => $dict->id,
            'devanagari' => 'योग',
            'iast' => 'yoga',
            'cyrillic' => 'йога',
            'translation' => 'соединение, метод, йога',
        ], $word));
    }

    public function test_to_slp1_maps_the_golden_trap_cases(): void
    {
        // Digraphs collapse to one phoneme; long vowels, retroflexes, sibilants.
        $this->assertSame('yoga', IastToSlp1::toSlp1('yoga'));
        $this->assertSame('kfzRa', IastToSlp1::toSlp1('kṛṣṇa'));
        $this->assertSame('Darma', IastToSlp1::toSlp1('dharma'));
        $this->assertSame('E', IastToSlp1::toSlp1('ai'));
        $this->assertSame('OzaDi', IastToSlp1::toSlp1('auṣadhi'));

        // Visarga and anusvāra (the acceptance traps).
        $this->assertSame('duHKa', IastToSlp1::toSlp1('duḥkha'));
        $this->assertSame('saMsAra', IastToSlp1::toSlp1('saṃsāra'));
    }

    public function test_key_for_lowercases_normalizes_and_rejects_unusable_keys(): void
    {
        // Proper-noun capital would otherwise become the WRONG phoneme ('K' = kh).
        $this->assertSame('kfzRa', IastToSlp1::keyFor('Kṛṣṇa'));

        // NFC: decomposed ā (a + U+0304) must map like precomposed ā.
        $this->assertSame('AtmA', IastToSlp1::keyFor("a\u{0304}tma\u{0304}"));

        // Avagraha 500s Cologne getword.php on every dictionary (H3762 probe) — no key.
        $this->assertNull(IastToSlp1::keyFor("so 'ham"));
        // Multi-word / hyphenated / empty headwords carry no single Cologne key.
        $this->assertNull(IastToSlp1::keyFor('rāma rājya'));
        $this->assertNull(IastToSlp1::keyFor(''));
        $this->assertNull(IastToSlp1::keyFor(null));
    }

    public function test_headword_cleanup_handles_real_slovar_formatting(): void
    {
        // Kochergina /wrapper/ (the dominant prod shape, 11k+ rows).
        $this->assertStringContainsString('key=yoga&', CdslLinks::forIast('/yoga/')[0]['url']);

        // Variant list → first variant only.
        $this->assertStringContainsString('key=duzyanta&', CdslLinks::forIast('/duṣyanta, duḥṣanta/')[0]['url']);

        // Bound forms / compound division → hyphen-free stem.
        $this->assertStringContainsString('key=akza&', CdslLinks::forIast('/-akṣa/')[0]['url']);
        $this->assertStringContainsString('key=sam&', CdslLinks::forIast('/sam-/')[0]['url']);

        // Avagraha still yields NO links (Cologne getword 500s on it — H3762 probe).
        $this->assertSame([], CdslLinks::forIast("/so'ham/"));
        // Cyrillic-homoglyph dirt in the iast column yields NO links.
        $this->assertSame([], CdslLinks::forIast('/dorака/'));
    }

    public function test_links_carry_the_canonical_cologne_url_shape(): void
    {
        $links = CdslLinks::forIast('saṃsāra');

        // AP90 is deliberately absent: its getword keys are nominative headwords
        // (agniH, yogaH — live-verified 31-08-2026), so stem keys would 404-class.
        $this->assertCount(2, $links);
        $this->assertSame(['mw', 'pwg'], array_column($links, 'code'));
        $this->assertSame(
            'https://www.sanskrit-lexicon.uni-koeln.de/scans/MWScan/2020/web/webtc/indexcaller.php?key=saMsAra&transLit=slp1&filter=roman',
            $links[0]['url'],
        );
        $this->assertStringContainsString('/PWGScan/2020/', $links[1]['url']);
        $this->assertStringNotContainsString('AP90', implode(' ', array_column($links, 'url')));
    }

    public function test_word_page_renders_cdsl_block_for_resolvable_headword(): void
    {
        $this->makeWord();

        $res = $this->get('/slovar/yoga')->assertOk();
        $res->assertSee('В словарях CDSL');
        $res->assertSee('scans/MWScan/2020/web/webtc/indexcaller.php?key=yoga&amp;transLit=slp1&amp;filter=roman', false);
        $res->assertSee('rel="nofollow noopener"', false);
    }

    public function test_word_page_omits_cdsl_block_when_key_is_unresolvable(): void
    {
        // Cyrillic-only headword → no SLP1 key → no block, no dead links.
        $this->makeWord(['iast' => null, 'devanagari' => null, 'cyrillic' => 'атман', 'translation' => 'дух']);

        $res = $this->get('/slovar/atman')->assertOk();
        $res->assertDontSee('В словарях CDSL');
        $res->assertDontSee('indexcaller.php');
    }
}
