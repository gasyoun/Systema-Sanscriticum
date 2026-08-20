<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\StudentCabinetGuideController;
use App\Models\User;
use App\Support\MarkdownGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Механическая приёмка студенческого гида (H3212, волна 1).
 */
class StudentCabinetGuideCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function guideText(): string
    {
        $path = base_path(StudentCabinetGuideController::SOURCE);

        $this->assertFileExists($path, 'Гид студента отсутствует: '.StudentCabinetGuideController::SOURCE);

        $text = (string) file_get_contents($path);

        $this->assertNotSame('', trim($text), 'Гид студента пуст.');

        return $text;
    }

    private function partOne(string $text): string
    {
        $start = mb_strpos($text, '# Часть I.');
        $end = mb_strpos($text, '# Часть II.');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($text, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function partOneSlugs(string $partOne): array
    {
        preg_match_all(
            '#screenshots/student-guide/([a-z0-9-]+)-1440\.png#',
            $partOne,
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    private function shotsOptional(): bool
    {
        $raw = (string) (getenv('GUIDE_SHOTS_OPTIONAL') ?: env('GUIDE_SHOTS_OPTIONAL', ''));

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function test_guide_file_exists_and_has_four_parts_and_seven_scenarios(): void
    {
        $text = $this->guideText();

        foreach (['Часть I', 'Часть II', 'Часть III', 'Часть IV'] as $part) {
            $this->assertStringContainsString($part, $text, "В гиде нет раздела «{$part}».");
        }

        preg_match_all('/^### Шаги\s*$/mu', $this->partOne($text), $matches);
        $this->assertCount(7, $matches[0], 'В части I должно быть семь сценариев (заголовок «### Шаги»).');

        $this->assertStringContainsString('https://samskrte.ru/faq/dz', $text);
        $this->assertStringContainsString('/help/prana-balance', $text);
    }

    public function test_every_part_one_scenario_names_desktop_and_phone_shots(): void
    {
        $partOne = $this->partOne($this->guideText());
        $slugs = $this->partOneSlugs($partOne);

        $this->assertCount(7, $slugs, 'В части I семь кадров 1440: '.implode(', ', $slugs));

        foreach ($slugs as $slug) {
            $this->assertStringContainsString(
                "screenshots/student-guide/{$slug}-390.png",
                $partOne,
                "У шага {$slug} нет телефонного кадра -390."
            );
        }
    }

    public function test_screenshot_files_exist_unless_optional(): void
    {
        $slugs = $this->partOneSlugs($this->partOne($this->guideText()));
        $dir = base_path('docs/screenshots/student-guide');
        $missing = [];

        foreach ($slugs as $slug) {
            foreach (['1440', '390'] as $width) {
                $file = $dir.'/'.$slug.'-'.$width.'.png';
                if (! is_file($file)) {
                    $missing[] = 'student-guide/'.$slug.'-'.$width.'.png';
                }
            }
        }

        if ($missing !== [] && $this->shotsOptional()) {
            $this->markTestSkipped(
                'GUIDE_SHOTS_OPTIONAL=1: кадров нет (Chrome не снимал). Недостаёт: '.implode(', ', $missing)
            );
        }

        $this->assertSame(
            [],
            $missing,
            "Нет PNG (снимите: node scripts/capture-guide-screenshots.mjs --guide student):\n  "
                .implode("\n  ", $missing)
        );
    }

    public function test_guide_does_not_leak_internal_names(): void
    {
        $text = $this->guideText();
        $body = preg_replace('/\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;

        foreach (['RoleGate', 'BlockAccessMaterializer', 'PaymentObserver'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "Внутреннее имя «{$needle}» утекло в текст гида."
            );
        }
    }

    public function test_help_page_is_ok_for_a_student_and_rewrites_screenshots(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('student.help'))
            ->assertOk()
            ->assertSee('Как пользоваться', false)
            ->assertSee('Войти в кабинет', false)
            ->assertSee(MarkdownGuide::SCREENSHOT_BASE.'student-guide/', false)
            ->assertDontSee('src="screenshots/', false);
    }

    public function test_help_page_is_not_ok_for_a_guest(): void
    {
        $this->get('/dvaram/help')->assertRedirect();
        $this->get('/dvaram/help')->assertStatus(302);
    }

    public function test_cabinet_nav_links_the_guide(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Как пользоваться', false)
            ->assertSee(route('student.help'), false);
    }
}
