<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H6303 — Put lesson materials before homework on narrow screens (ticket L1,
 * docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md).
 *
 * DOM-order assertions run against real page renders: the side column
 * (transcript / materials / notes tabs) must precede the homework block in the
 * rendered HTML for recorded, live, no-transcript and no-homework fixtures.
 * The responsive CSS contract pins the visual mechanism: mobile stacks side
 * column above homework, desktop keeps the two-column grid geometry.
 */
class LessonMobileMaterialsOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SIDE_COL = 'class="lesson-side-col';

    private const HOMEWORK_COL = 'class="lesson-homework-col';

    private const HOMEWORK_HEADING = '>Домашнее задание</h3>';

    private const NO_HOMEWORK = 'Домашнего задания для этого урока нет';

    private const TRANSCRIPT_SEARCH = 'Поиск фразы...';

    private const MATERIALS_HEADING = 'Материалы к уроку';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
        Cache::flush();
        Storage::fake('local');
    }

    /** @test */
    public function recorded_lesson_puts_transcript_and_materials_before_homework_in_dom(): void
    {
        $lesson = $this->makeLesson(
            ['youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk'],
            withTranscript: true,
            withAttachments: true,
            withHomework: true,
        );

        $html = $this->lessonHtml($lesson);

        $this->assertOrderedMaterialsBeforeHomework($html);
    }

    /** @test */
    public function live_lesson_without_recording_keeps_tabs_before_homework_in_dom(): void
    {
        $lesson = $this->makeLesson([], withTranscript: false, withAttachments: true, withHomework: true);

        $html = $this->lessonHtml($lesson);

        $this->assertOrderedMaterialsBeforeHomework($html);
    }

    /** @test */
    public function no_transcript_lesson_still_orders_materials_before_homework_in_dom(): void
    {
        $lesson = $this->makeLesson(
            ['youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk'],
            withTranscript: false,
            withAttachments: true,
            withHomework: true,
        );

        $html = $this->lessonHtml($lesson);

        $this->assertStringContainsString(self::MATERIALS_HEADING, $html, 'materials panel must render');
        $this->assertStringNotContainsString(self::TRANSCRIPT_SEARCH, $html);
        $this->assertOrderedMaterialsBeforeHomework($html);
    }

    /** @test */
    public function no_homework_lesson_renders_empty_state_after_side_column(): void
    {
        $lesson = $this->makeLesson(
            ['youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk'],
            withTranscript: true,
            withAttachments: false,
            withHomework: false,
        );

        $html = $this->lessonHtml($lesson);

        $side = mb_strpos($html, self::SIDE_COL);
        $noHomework = mb_strpos($html, self::NO_HOMEWORK);

        $this->assertNotFalse($side);
        $this->assertNotFalse($noHomework, 'explicit no-homework state must render');
        $this->assertLessThan($noHomework, $side, 'side column must come before the no-homework state');
    }

    /** @test */
    public function responsive_css_contract_keeps_desktop_grid_and_mobile_stacking(): void
    {
        $lesson = $this->makeLesson(
            ['youtube_url' => 'https://www.youtube.com/watch?v=abcdefghijk'],
            withTranscript: true,
            withAttachments: true,
            withHomework: true,
        );

        $html = $this->lessonHtml($lesson);

        // Desktop (≥768px): the exact two-column geometry is preserved — main
        // column left, sticky 420px side column right, homework spanning the
        // left column below the main column.
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) 420px', $html);
        $this->assertStringContainsString('"main side"', $html);
        $this->assertStringContainsString('"homework side"', $html);
        $this->assertStringContainsString('.lesson-main-col { grid-area: main', $html);
        $this->assertStringContainsString('grid-area: side;', $html);
        $this->assertStringContainsString('.lesson-homework-col { grid-area: homework', $html);
        $this->assertStringContainsString('position: sticky', $html);

        // Narrow screens (<768px): single flex column, DOM order = visual order
        // (player → side col with transcript/materials/notes → homework).
        $this->assertStringContainsString('.lesson-layout {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;', $html);
        $this->assertStringContainsString(self::HOMEWORK_COL, $html);
    }

    private function assertOrderedMaterialsBeforeHomework(string $html): void
    {
        $side = mb_strpos($html, self::SIDE_COL);
        $transcript = mb_strpos($html, self::TRANSCRIPT_SEARCH);
        $materials = mb_strpos($html, self::MATERIALS_HEADING);
        $homework = mb_strpos($html, self::HOMEWORK_HEADING);
        $homeworkCol = mb_strpos($html, self::HOMEWORK_COL);

        $this->assertNotFalse($side, 'side column must render');
        $this->assertNotFalse($homework, 'homework block must render');
        $this->assertNotFalse($homeworkCol, 'homework must live in its own column container');
        $this->assertLessThan($homework, $side, 'side column must come before homework in DOM');
        $this->assertLessThan($homework, $homeworkCol, 'homework column must wrap the homework block');
        if ($transcript !== false) {
            $this->assertLessThan($homework, $transcript, 'transcript must come before homework in DOM');
        }
        if ($materials !== false) {
            $this->assertLessThan($homework, $materials, 'materials must come before homework in DOM');
        }
    }

    private function makeLesson(
        array $attributes = [],
        bool $withTranscript = false,
        bool $withAttachments = false,
        bool $withHomework = false,
    ): Lesson {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->free()->create(array_merge([
            'homework_enabled' => $withHomework,
            'homework_prompt' => $withHomework ? 'Перескажите правило сандхи своими словами.' : null,
            'attachments' => $withAttachments ? ['files/handout.pdf'] : null,
        ], $attributes));

        if ($withTranscript) {
            $words = [];
            $start = 41.0;
            foreach (explode(' ', 'Ом шанти шанти шантих.') as $word) {
                $words[] = ['word' => $word, 'punctuated_word' => $word, 'start' => $start, 'end' => $start + 0.4];
                $start += 0.5;
            }
            $path = 'transcripts/lesson-'.$lesson->id.'.json';
            Storage::disk('local')->put($path, json_encode(
                ['results' => ['channels' => [['alternatives' => [['words' => $words]]]]]],
                JSON_UNESCAPED_UNICODE,
            ));
            $lesson->forceFill(['transcript_file' => $path])->save();
        }

        return $lesson;
    }

    private function lessonHtml(Lesson $lesson): string
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]));

        $response->assertOk();

        $html = $response->getContent();

        // H6303 acceptance hook: export the rendered markup for a real-browser
        // viewport probe (320/390/768px) without touching production. No-op by default.
        if (getenv('H6303_DUMP_HTML') !== false && getenv('H6303_DUMP_HTML') !== '') {
            file_put_contents(getenv('H6303_DUMP_HTML'), $html);
        }

        return $html;
    }
}
