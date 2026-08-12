<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherGuide;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Механическая половина приёмки руководства преподавателя (H2501, волна 1).
 *
 * Проверяет ровно то, на что не стоит тратить внимание живого преподавателя:
 * не пропустил ли гид раздел, который преподаватель видит в меню, и открывается
 * ли страница руководства тому, кому положено.
 */
class TeacherGuideCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function guideText(): string
    {
        $path = TeacherGuide::sourcePath();

        $this->assertFileExists($path, 'Руководство преподавателя отсутствует: '.TeacherGuide::SOURCE);

        $text = (string) file_get_contents($path);

        $this->assertNotSame('', trim($text), 'Руководство преподавателя пусто.');

        return $text;
    }

    /**
     * Заголовки руководства в нормализованном виде.
     *
     * @return array<int, string>
     */
    private function guideHeadings(string $text): array
    {
        preg_match_all('/^#{1,3}\s+(.+?)\s*$/mu', $text, $matches);

        return array_map(fn (string $h): string => $this->normalize($h), $matches[1]);
    }

    /**
     * Сверка идёт по НАЗВАНИЮ раздела, поэтому регистр, ё, дефисы и лишние
     * пробелы значения не имеют — а расхождение по существу имеет.
     */
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
        $output = tempnam(sys_get_temp_dir(), 'nav-census').'.json';

        $exit = Artisan::call('teacher:nav-census', ['--output' => $output]);

        $this->assertSame(0, $exit, 'teacher:nav-census завершилась с ошибкой: '.Artisan::output());
        $this->assertFileExists($output);

        $payload = json_decode((string) file_get_contents($output), true);

        @unlink($output);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('items', $payload);

        return $payload;
    }

    /** @test */
    public function guide_file_exists_and_is_not_empty(): void
    {
        $text = $this->guideText();

        $this->assertGreaterThan(5000, mb_strlen($text), 'Руководство подозрительно короткое.');

        foreach (['Часть I', 'Часть II', 'Часть III', 'Часть IV'] as $part) {
            $this->assertStringContainsString($part, $text, "В руководстве нет раздела «{$part}».");
        }
    }

    /** @test */
    public function every_section_the_teacher_sees_has_a_heading_in_the_guide(): void
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

    /** @test */
    public function every_scenario_step_carries_a_reversibility_badge(): void
    {
        $text = $this->guideText();

        // Часть I — от её заголовка до заголовка части II.
        $start = mb_strpos($text, '# Часть I.');
        $end = mb_strpos($text, '# Часть II.');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $partOne = mb_substr($text, $start, $end - $start);

        // Нумерованные шаги сценариев — строки вида "1. ..." на верхнем уровне списка.
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

    /** @test */
    public function guide_does_not_leak_internal_names(): void
    {
        $text = $this->guideText();

        // Тело руководства без ссылок на соседние документы: имя файла в ссылке —
        // это адрес, а не внутреннее имя, утёкшее в текст для преподавателя.
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

    /** @test */
    public function guide_page_is_open_to_a_teacher(): void
    {
        $teacher = User::factory()->create(['role' => Roles::TEACHER]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacher);

        $this->assertTrue(TeacherGuide::canAccess());

        $this->get(TeacherGuide::getUrl())->assertOk();
    }

    /** @test */
    public function guide_page_is_closed_to_a_non_teacher(): void
    {
        // Менеджер в панель заходит, но к преподавательскому руководству отношения не имеет.
        $manager = User::factory()->create(['role' => Roles::MANAGER]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($manager);

        $this->assertFalse(TeacherGuide::canAccess());

        $this->get(TeacherGuide::getUrl())->assertForbidden();
    }
}
