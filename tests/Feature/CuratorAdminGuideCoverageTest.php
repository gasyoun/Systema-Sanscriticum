<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CuratorGuide;
use App\Models\User;
use App\Support\MarkdownGuide;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Механическая приёмка руководства куратора (H3213, волна 2).
 */
class CuratorAdminGuideCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function guideText(): string
    {
        $path = CuratorGuide::sourcePath();

        $this->assertFileExists($path, 'Руководство куратора отсутствует: '.CuratorGuide::SOURCE);

        $text = (string) file_get_contents($path);

        $this->assertNotSame('', trim($text), 'Руководство куратора пусто.');

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function guideHeadings(string $text): array
    {
        preg_match_all('/^#{1,3}\s+(.+?)\s*$/mu', $text, $matches);

        return array_map(fn (string $h): string => $this->normalize($h), $matches[1]);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['ё', '—', '–', '−'], ['е', '-', '-', '-'], $value);
        $value = preg_replace('/[«»"“”\'`]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function census(): array
    {
        $output = tempnam(sys_get_temp_dir(), 'mgr-census').'.json';

        $exit = Artisan::call('manager:nav-census', ['--output' => $output]);

        $this->assertSame(0, $exit, 'manager:nav-census завершилась с ошибкой: '.Artisan::output());
        $this->assertFileExists($output);

        $payload = json_decode((string) file_get_contents($output), true);

        @unlink($output);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('items', $payload);

        return $payload;
    }

    private function partOne(string $text): string
    {
        $start = mb_strpos($text, '# Часть I.');
        $end = mb_strpos($text, '# Часть II.');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($text, $start, $end - $start);
    }

    private function shotsOptional(): bool
    {
        $raw = (string) (getenv('GUIDE_SHOTS_OPTIONAL') ?: env('GUIDE_SHOTS_OPTIONAL', ''));

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /** H3761: сценариев стало восемь — добавлен «Ученик ходил, а посещаемость пустая». */
    public function test_guide_file_exists_and_has_four_parts_and_eight_scenarios(): void
    {
        $text = $this->guideText();

        foreach (['Часть I', 'Часть II', 'Часть III', 'Часть IV'] as $part) {
            $this->assertStringContainsString($part, $text, "В руководстве нет раздела «{$part}».");
        }

        preg_match_all('/^### Шаги\s*$/mu', $this->partOne($text), $matches);
        $this->assertCount(8, $matches[0], 'В части I должно быть восемь сценариев (заголовок «### Шаги»).');
        $this->assertStringContainsString('Ученик ходил, а посещаемость пустая', $text);

        $this->assertStringContainsString('login-link', $text);
        $this->assertStringContainsString('Разблокировать (ссылка для входа)', $text);
        $this->assertStringContainsString('grokusaurus_bot', $text);
        $this->assertStringContainsString('Отдел заботы', $text);
        $this->assertStringContainsString('https://samskrte.ru/dvaram', $text);
        $this->assertStringContainsString('https://samskrte.ru/faq/dz', $text);
    }

    public function test_every_section_the_manager_sees_has_a_heading_in_the_guide(): void
    {
        $census = $this->census();
        $headings = $this->guideHeadings($this->guideText());

        $missing = [];

        foreach ($census['items'] as $item) {
            $label = $this->normalize((string) $item['label']);

            if ($label === '') {
                continue;
            }

            $covered = false;

            foreach ($headings as $heading) {
                if ($heading === $label || str_contains($heading, $label)) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $missing[] = $item['group'].' → '.$item['label'];
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Разделы из переписи без заголовка в руководстве:\n  ".implode("\n  ", $missing)
        );
    }

    public function test_every_scenario_step_carries_a_reversibility_badge(): void
    {
        $partOne = $this->partOne($this->guideText());

        preg_match_all('/^\d+\.\s+(.+)$/mu', $partOne, $matches);

        $this->assertNotEmpty($matches[1], 'В части I не нашлось ни одного шага сценария.');

        $withoutBadge = [];

        foreach ($matches[1] as $step) {
            $hasBadge = str_contains($step, '**[Безопасно]**')
                || str_contains($step, '**[Видно студенту]**')
                || str_contains($step, '**[Необратимо]**');

            if (! $hasBadge) {
                $withoutBadge[] = mb_substr($step, 0, 80);
            }
        }

        $this->assertSame(
            [],
            $withoutBadge,
            "Шаги сценариев без пометки обратимости:\n  ".implode("\n  ", $withoutBadge)
        );
    }

    public function test_guide_does_not_leak_internal_names(): void
    {
        $text = $this->guideText();
        $body = preg_replace('/\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;

        $forbidden = [
            'canViewAny', 'canAccess', 'RoleGate', 'Resource::', 'Filament',
            'teacher_id', 'is_admin', 'php artisan', 'Str::markdown', '::class',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "Внутреннее имя «{$needle}» утекло в текст руководства."
            );
        }
    }

    public function test_admin_manual_redirects_to_the_new_guide(): void
    {
        $path = base_path('docs/admin-manual.md');
        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);

        $this->assertStringContainsString('CURATOR_ADMIN_GUIDE_RU.md', $text);
        $this->assertStringContainsString('/admin/curator-guide', $text);
        $this->assertStringNotContainsString('функции последнего месяца', $text);
    }

    public function test_screenshot_files_exist_unless_optional(): void
    {
        $partOne = $this->partOne($this->guideText());
        preg_match_all(
            '#screenshots/curator-guide/([a-z0-9-]+)-1440\.png#',
            $partOne,
            $matches
        );
        $slugs = array_values(array_unique($matches[1]));
        $this->assertGreaterThanOrEqual(6, count($slugs), 'В части I мало кадров 1440.');

        $dir = base_path('docs/screenshots/curator-guide');
        $missing = [];

        foreach ($slugs as $slug) {
            foreach (['1440', '390'] as $width) {
                $file = $dir.'/'.$slug.'-'.$width.'.png';
                if (! is_file($file)) {
                    $missing[] = 'curator-guide/'.$slug.'-'.$width.'.png';
                }
            }
        }

        if ($missing !== [] && ($this->shotsOptional() || $this->noPngsOnDisk($dir))) {
            $this->markTestSkipped(
                'Кадра нет (Chrome не снимал, решение 23). Недостаёт: '.implode(', ', $missing)
            );
        }

        $this->assertSame(
            [],
            $missing,
            "Нет PNG (снимите: node scripts/capture-guide-screenshots.mjs --guide curator):\n  "
                .implode("\n  ", $missing)
        );
    }

    public function test_no_money_screen_screenshot_exists(): void
    {
        $found = [];

        foreach (glob(base_path('docs/screenshots/curator-guide').'/*.png') ?: [] as $file) {
            $name = mb_strtolower(basename($file));

            foreach (['salary', 'salaries', 'payout', 'settlement', 'payment', 'debtor', 'receivable', 'profit', 'finans'] as $word) {
                if (str_contains($name, $word)) {
                    $found[] = $name;
                    break;
                }
            }
        }

        $this->assertSame([], $found, 'Денежные кадры в руководстве куратора: '.implode(', ', $found));
    }

    public function test_the_panel_page_rewrites_relative_screenshot_paths(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($manager);

        $html = (new CuratorGuide)->guideHtml();

        $this->assertIsString($html);
        $this->assertStringContainsString(MarkdownGuide::SCREENSHOT_BASE.'curator-guide/', $html);
        $this->assertStringNotContainsString('src="screenshots/', $html);
        $this->assertStringContainsString('Разобрать обращение в чате', $html);
    }

    public function test_guide_page_is_open_to_a_manager_and_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);
        $this->assertTrue(CuratorGuide::canAccess());
        $this->get(CuratorGuide::getUrl())->assertOk();

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);
        $this->assertTrue(CuratorGuide::canAccess());
        $this->get(CuratorGuide::getUrl())->assertOk();
    }

    public function test_guide_page_is_closed_to_a_teacher_without_manager(): void
    {
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacher);

        $this->assertFalse(CuratorGuide::canAccess());
        $this->get(CuratorGuide::getUrl())->assertForbidden();
    }

    private function noPngsOnDisk(string $dir): bool
    {
        $pngs = glob($dir.'/*.png') ?: [];

        return $pngs === [];
    }
}
