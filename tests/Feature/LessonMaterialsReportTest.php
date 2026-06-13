<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Support\LessonMaterialsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сводка покрытия уроков материалами + паритет PHP-аксессоров и SQL-скоупов.
 */
class LessonMaterialsReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedLessons(): void
    {
        $course = Course::factory()->create();
        $make = fn (array $attrs) => Lesson::factory()->for($course)->create($attrs);

        $make(['youtube_url' => 'https://youtu.be/abc12345678']);        // видео → core
        $make(['attachments' => ['lesson-materials/a.pdf', 'lesson-materials/b.mp3']]); // вложения → core
        $make(['transcript_file' => 'transcripts/t.json']);              // транскрипт → core
        $make(['homework_enabled' => true, 'homework_prompt' => 'Сделать упражнение']); // только ДЗ → НЕ core
        $make([]);                                                       // пусто → НЕ core
        $make(['flash_cards' => ['карточка1', 'карточка2']]);            // только флешкарты → НЕ core
        $make(['attachments' => []]);                                    // пустой массив вложений → НЕ core
    }

    /** @test */
    public function overall_counts_coverage_and_types(): void
    {
        $this->seedLessons();

        $r = LessonMaterialsReport::overall();

        $this->assertSame(7, $r['total']);
        $this->assertSame(3, $r['withMaterials']);   // видео + вложения + транскрипт
        $this->assertSame((int) round(3 / 7 * 100), $r['percent']);
        $this->assertSame(1, $r['video']);
        $this->assertSame(1, $r['attachments']);     // пустой массив не считается
        $this->assertSame(1, $r['transcript']);
        $this->assertSame(1, $r['homework']);
        $this->assertSame(1, $r['flashcards']);
    }

    /** @test */
    public function sql_scopes_match_php_accessors(): void
    {
        $this->seedLessons();

        $all = Lesson::all();
        $predicates = [
            'withVideo' => fn (Lesson $l): bool => $l->hasVideo(),
            'withTranscript' => fn (Lesson $l): bool => $l->hasTranscript(),
            'withAttachments' => fn (Lesson $l): bool => $l->attachmentsCount() > 0,
            'withFlashcards' => fn (Lesson $l): bool => $l->hasFlashcards(),
            'withHomework' => fn (Lesson $l): bool => $l->hasHomeworkMaterial(),
            'withCoreMaterials' => fn (Lesson $l): bool => $l->hasCoreMaterials(),
            'withoutCoreMaterials' => fn (Lesson $l): bool => ! $l->hasCoreMaterials(),
        ];

        foreach ($predicates as $scope => $predicate) {
            $sqlCount = Lesson::query()->{$scope}()->count();
            $phpCount = $all->filter($predicate)->count();
            $this->assertSame($phpCount, $sqlCount, "Расхождение SQL/PHP в скоупе {$scope}");
        }
    }
}
