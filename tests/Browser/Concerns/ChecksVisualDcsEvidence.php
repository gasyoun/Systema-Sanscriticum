<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Learning\ExternalLearningCatalog;
use App\Services\Learning\VisualDcsReleaseImporter;
use Laravel\Dusk\Browser;

/**
 * H2869 — общая механика браузерных доказательств Wave L шага 8.
 *
 * Кадры и проверки живут в двух классах (обычный и reduced-motion — у них
 * разные аргументы запуска Chrome), а посев данных, аудит контраста и проверка
 * горизонтального скролла — здесь, чтобы оба класса мерили одним и тем же
 * инструментом.
 */
trait ChecksVisualDcsEvidence
{
    /** Куда кладём кадры — путь от корня репозитория; папка коммитится. */
    private static string $targetDir = 'docs/screenshots/visualdcs-learner';

    /** Ширины из acceptance H2482/H2869: десктоп и узкий мобильный. */
    private static int $desktop = 1440;

    private static int $mobile = 390;

    /**
     * Аудит контраста внутри контейнера поверхности (`.max-w-3xl` — корень
     * каждой VisualDCS-вьюхи). Хром сайта (шапка витрины, меню кабинета) —
     * не предмет шага 8, поэтому за контейнер не выходим.
     *
     * Возвращает {failures: [...], brand: [...]}: `failures` — текст ниже
     * порога WCAG AA (4.5:1, для крупного/жирного 3:1); `brand` — текст на
     * фирменном оранжевом фоне #e85c24 (кнопки всего сайта) — меряем и
     * показываем в отчёте, но падение теста из-за фирменной палитры было бы
     * решением о бренде, а его принимает человек, не этот тест.
     */
    private function contrastAudit(Browser $browser): array
    {
        $js = <<<'JS'
            var root = document.querySelector('.max-w-3xl');
            if (!root) { return {failures: ['no .max-w-3xl container on page'], brand: []}; }
            function lin(c) { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
            function lum(rgb) { return 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]); }
            function parse(s) {
                var m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/.exec(s || '');
                return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
            }
            function bgOf(el) {
                for (var n = el; n; n = n.parentElement) {
                    var c = parse(getComputedStyle(n).backgroundColor);
                    if (c && c[3] > 0.9) { return c; }
                }
                return parse(getComputedStyle(document.body).backgroundColor) || [255, 255, 255, 1];
            }
            var failures = [], brand = [];
            root.querySelectorAll('*').forEach(function (el) {
                var hasText = Array.prototype.some.call(el.childNodes, function (n) {
                    return n.nodeType === 3 && n.textContent.trim() !== '';
                });
                if (!hasText) { return; }
                var st = getComputedStyle(el);
                if (st.visibility === 'hidden' || st.display === 'none' || parseFloat(st.opacity) === 0) { return; }
                var r = el.getBoundingClientRect();
                if (r.width < 2 || r.height < 2) { return; } // sr-only и схлопнутые узлы
                var fg = parse(st.color);
                if (!fg || fg[3] < 0.9) { return; }
                var bg = bgOf(el);
                var L1 = lum(fg), L2 = lum(bg);
                var ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
                var size = parseFloat(st.fontSize);
                var bold = parseInt(st.fontWeight, 10) >= 700;
                var need = (size >= 24 || (size >= 18.66 && bold)) ? 3 : 4.5;
                var line = el.tagName + ' "' + el.textContent.trim().slice(0, 40) + '" '
                    + st.color + ' on rgb(' + bg.slice(0, 3).join(',') + ') = '
                    + ratio.toFixed(2) + ' (need ' + need + ')';
                if (bg[0] === 232 && bg[1] === 92 && bg[2] === 36) { brand.push(line); return; }
                if (ratio < need) { failures.push(line); }
            });
            return {failures: failures, brand: brand};
        JS;

        return $browser->script('return (function(){'.$js.'})();')[0];
    }

    private function assertContrastClean(Browser $browser, string $page): array
    {
        $audit = $this->contrastAudit($browser);
        $this->assertSame(
            [],
            $audit['failures'],
            "Контраст ниже WCAG AA на «{$page}»:\n".implode("\n", $audit['failures'])
        );

        return $audit['brand'] ?? [];
    }

    private function assertNoHorizontalOverflow(Browser $browser, string $page): void
    {
        $spill = (int) $browser->script(
            'return document.documentElement.scrollWidth - document.documentElement.clientWidth;'
        )[0];
        $this->assertLessThanOrEqual(1, $spill, "Горизонтальный скролл на «{$page}»: {$spill}px лишней ширины.");
    }

    /** Импорт фикстурного релиза (complete | sparse) с промоушеном. */
    private function importFixture(string $kind): void
    {
        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/'.$kind));
    }

    private function paidUser(): User
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user->fresh();
    }

    /** id пассажа из промоушенного каталога по подстроке. */
    private function passageId(string $needle): string
    {
        $items = app(ExternalLearningCatalog::class)->list('passage', false, true);
        foreach ($items as $item) {
            if (str_contains((string) $item['id'], $needle)) {
                return (string) $item['id'];
            }
        }

        $this->fail("В промоушенном релизе нет пассажа с id ~ «{$needle}» — фикстура изменилась?");
    }

    private function requireFlagsOn(): void
    {
        foreach (['visualdcs_verb', 'visualdcs_nominal', 'visualdcs_passage'] as $flag) {
            if (! config('features.'.$flag)) {
                $this->markTestSkipped(
                    'Флаги VISUALDCS_VERB/NOMINAL/PASSAGE выключены. Это dusk-окружение: '
                    .'включите все три в .env.dusk.local (прод не трогается) и перезапустите.'
                );
            }
        }
    }
}
