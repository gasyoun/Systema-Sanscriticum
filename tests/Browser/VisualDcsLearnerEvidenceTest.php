<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksVisualDcsEvidence;
use Tests\DuskTestCase;

/**
 * H2869 — Wave L шаг 8: браузерные доказательства трёх поверхностей VisualDCS.
 *
 * Feature-тесты H2482 проверяли HTML (200, классы фокуса, overflow-классы), но
 * acceptance формулирует «Browser evidence at 1440px/390px proves
 * keyboard/focus/contrast/reduced-motion/no-overflow» — то есть настоящий
 * рендер. Этот тест снимает кадры обеих фикстур (complete и sparse) на обеих
 * ширинах в docs/screenshots/visualdcs-learner/ и на каждой странице меряет:
 *
 * - горизонтальный скролл (scrollWidth vs clientWidth) — его быть не должно;
 * - контраст WCAG AA по вычисленным стилям (см. трейт: фирменный оранжевый фон
 *   меряется, но решение о бренд-палитре остаётся человеку);
 * - видимость клавиатурного фокуса — реальный Tab, реальное focus-visible
 *   кольцо (box-shadow), не наличие CSS-класса в разметке.
 *
 * Reduced-motion — отдельный класс VisualDcsReducedMotionTest: ему нужен
 * другой запуск Chrome (--force-prefers-reduced-motion).
 *
 * Как запускать — docs/DUSK_LOCAL_WINDOWS.md; флаги VISUALDCS_* включаются в
 * .env.dusk.local (локальное dusk-окружение, прод-дефолты OFF не трогаются).
 */
class VisualDcsLearnerEvidenceTest extends DuskTestCase
{
    use ChecksVisualDcsEvidence;
    use DatabaseTruncation;

    /** Замеры текста на фирменном фоне — печатаются в конце как справка. */
    private static array $brandNotes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Browser::$storeScreenshotsAt = base_path(self::$targetDir);
        $this->requireFlagsOn();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$brandNotes !== []) {
            fwrite(STDOUT, "\nТекст на фирменном фоне (справка, не провал):\n  "
                .implode("\n  ", array_unique(self::$brandNotes))."\n");
        }
        parent::tearDownAfterClass();
    }

    public function test_complete_release_is_clean_at_both_widths(): void
    {
        $this->importFixture('complete');
        $user = $this->paidUser();
        $linked = $this->passageId('hitopade-a:0');
        $zeroLink = $this->passageId('manusm-ti:10');

        $pages = [
            'preview-verb' => ['/visualdcs/verb/preview', 'Просмотр', false],
            'hub' => ['/dvaram/visualdcs', 'Глагол', true],
            'verb-index' => ['/dvaram/visualdcs/verb', 'ah', true],
            'nominal-index' => ['/dvaram/visualdcs/nominal', 'deva', true],
            'passage-index' => ['/dvaram/visualdcs/passage', 'Hitopadeśa', true],
            'passage-show-linked' => ['/dvaram/visualdcs/passage/'.rawurlencode($linked), 'Связанные формы', true],
            'passage-show-zerolink' => ['/dvaram/visualdcs/passage/'.rawurlencode($zeroLink), 'нет сверки с конкордансом', true],
        ];

        $this->browse(function (Browser $browser) use ($user, $pages) {
            $browser->loginAs($user);
            $this->shootAndAudit($browser, $pages);
        });
    }

    public function test_sparse_release_is_clean_at_both_widths(): void
    {
        $this->importFixture('sparse');
        $user = $this->paidUser();
        $sparsePassage = $this->passageId('manusm-ti');

        $pages = [
            'sparse-verb-index' => ['/dvaram/visualdcs/verb', 'abhibhañj', true],
            'sparse-passage-show' => ['/dvaram/visualdcs/passage/'.rawurlencode($sparsePassage), 'Manusmṛti', true],
        ];

        $this->browse(function (Browser $browser) use ($user, $pages) {
            $browser->loginAs($user);
            $this->shootAndAudit($browser, $pages);
        });
    }

    public function test_keyboard_focus_ring_is_visible(): void
    {
        $this->importFixture('complete');
        $user = $this->paidUser();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->resize(self::$desktop, 900)
                ->visit('/dvaram/visualdcs');

            $this->assertTabReachesVisibleRing($browser, '/dvaram/visualdcs/', 'хаб: ссылка на тренажёр');

            $browser->visit('/dvaram/visualdcs/verb');
            $this->assertTabReachesVisibleRing($browser, null, 'каталог глагола: поле поиска или ссылка');
        });
    }

    /**
     * Жмём настоящий Tab (до 40 раз), пока фокус не окажется на интерактивном
     * элементе контейнера поверхности, и проверяем, что focus-visible даёт
     * видимое кольцо: box-shadow (Tailwind ring) или outline.
     */
    private function assertTabReachesVisibleRing(Browser $browser, ?string $hrefNeedle, string $label): void
    {
        $probe = <<<'JS'
            var el = document.activeElement;
            if (!el || !el.closest('.max-w-3xl')) { return null; }
            var tag = el.tagName;
            if (tag !== 'A' && tag !== 'BUTTON' && tag !== 'INPUT') { return null; }
            var href = el.getAttribute('href') || '';
            var st = getComputedStyle(el);
            return {href: href, ring: (st.boxShadow !== 'none') || (st.outlineStyle !== 'none' && parseFloat(st.outlineWidth) > 0)};
        JS;

        $found = null;
        for ($i = 0; $i < 40; $i++) {
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::TAB);
            $hit = $browser->script('return (function(){'.$probe.'})();')[0];
            if ($hit === null) {
                continue;
            }
            if ($hrefNeedle === null || str_contains((string) $hit['href'], $hrefNeedle)) {
                $found = $hit;
                break;
            }
        }

        $this->assertNotNull($found, "Tab так и не дошёл до интерактивного элемента ({$label}).");
        $this->assertTrue((bool) $found['ring'], "Фокус без видимого кольца ({$label}).");
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: bool}>  $pages  slug => [путь, текст-якорь, нужен ли логин]
     */
    private function shootAndAudit(Browser $browser, array $pages): void
    {
        foreach ($pages as $slug => [$path, $anchor, $auth]) {
            foreach ([self::$desktop => 900, self::$mobile => 844] as $width => $height) {
                $browser->resize($width, $height)
                    ->visit($path)
                    ->assertSee($anchor);
                if ($auth) {
                    $browser->assertPathBeginsWith('/dvaram');
                }
                $browser->screenshot($slug.'-'.$width);
                $this->assertNoHorizontalOverflow($browser, "{$slug} @ {$width}px");
                self::$brandNotes = array_merge(
                    self::$brandNotes,
                    $this->assertContrastClean($browser, "{$slug} @ {$width}px")
                );
            }
        }
    }
}
