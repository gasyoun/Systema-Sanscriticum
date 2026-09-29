<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\Course;
use App\Models\Group;
use App\Models\KnowledgeChunk;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\User;
use App\Services\Bot\LessonQaService;
use App\Services\Knowledge\AccessibleLessonIds;
use App\Services\Knowledge\LessonRetriever;
use App\Services\Support\Faq\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Этап 4, ядро контура: бот отвечает по расшифровке ТОЛЬКО того занятия,
 * которое студенту реально открыто.
 *
 * Здесь проверяется не формулировка ответа, а граница: чужой фрагмент не должен
 * доходить даже до выдачи ретривера — иначе он попадёт в промпт, а оттуда в
 * сообщение. Это тот самый класс утечки, ради которого стенограммы платных
 * уроков вообще убрали с публичного диска (H3308).
 */
class LessonQaAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'features.lesson_qa' => true,
            'knowledge.driver' => 'ollama',
            'knowledge.dimensions' => 4,
            'knowledge.embedding_model' => 'test-model',
            'knowledge.lesson.min_score' => 0.0,
        ]);

        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]));
    }

    public function test_student_without_payment_gets_nothing_even_though_transcript_exists(): void
    {
        [$course, $lesson] = $this->paidLessonWithTranscript(block: 1, text: 'Шестой класс глаголов образует основу так.');
        $student = $this->studentInGroupOf($course);

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $this->assertSame(1, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());

        $this->assertSame([], app(AccessibleLessonIds::class)->forUser($student), 'без оплаты урок не открыт');
        $this->assertSame([], app(LessonRetriever::class)->retrieve($student, 'шестой класс глаголов'));

        $result = app(LessonQaService::class)->answer($student, 'шестой класс глаголов');
        $this->assertSame(LessonQaService::STATUS_NOTHING, $result['status']);
        $this->assertNull($result['text']);
    }

    public function test_one_paid_block_opens_exactly_its_own_lesson(): void
    {
        [$course, $paidLesson] = $this->paidLessonWithTranscript(block: 2, text: 'Оплаченный блок: сандхи перед гласной.');
        $foreignLesson = $this->lessonWithTranscript($course, block: 5, text: 'Чужой блок: тайное слово кувшин.');

        $student = $this->studentInGroupOf($course);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame([$paidLesson->id], app(AccessibleLessonIds::class)->forUser($student));

        $hits = app(LessonRetriever::class)->retrieve($student, 'тайное слово кувшин');
        $this->assertNotSame([], $hits, 'по оплаченному уроку выдача есть');

        foreach ($hits as $hit) {
            $this->assertSame($paidLesson->id, (int) $hit['chunk']->lesson_id);
            $this->assertStringNotContainsString('кувшин', (string) $hit['chunk']->text, 'текст чужого урока не должен попадать в выдачу');
        }
    }

    public function test_group_membership_without_any_key_does_not_open_the_lesson(): void
    {
        [$course] = $this->paidLessonWithTranscript(block: 1, text: 'Платный фрагмент.');
        $student = $this->studentInGroupOf($course);

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame([], app(AccessibleLessonIds::class)->forUser($student));
    }

    public function test_free_lesson_is_open_without_payment(): void
    {
        $course = Course::factory()->create();
        $lesson = $this->lessonWithTranscript($course, block: 1, text: 'Бесплатное занятие.', free: true);
        $student = $this->studentInGroupOf($course);

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame([$lesson->id], app(AccessibleLessonIds::class)->forUser($student));
    }

    public function test_personal_grant_opens_the_lesson(): void
    {
        [$course, $lesson] = $this->paidLessonWithTranscript(block: 3, text: 'Фрагмент по гранту.');
        $student = $this->studentInGroupOf($course);

        LessonAccessGrant::query()->create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'reason' => 'trial',
        ]);

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame([$lesson->id], app(AccessibleLessonIds::class)->forUser($student));
    }

    public function test_unpublished_lesson_is_never_indexed(): void
    {
        $course = Course::factory()->create();
        $lesson = $this->lessonWithTranscript($course, block: 1, text: 'Черновик занятия.', free: true);
        $lesson->forceFill(['is_published' => false])->save();

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame(0, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());
    }

    /** @return array{0: Course, 1: Lesson} */
    private function paidLessonWithTranscript(int $block, string $text): array
    {
        $course = Course::factory()->create();

        return [$course, $this->lessonWithTranscript($course, $block, $text)];
    }

    private function lessonWithTranscript(Course $course, int $block, string $text, bool $free = false): Lesson
    {
        $lesson = Lesson::factory()->for($course)->create([
            'block_number' => $block,
            'block_half' => null,
            'group_id' => null,
            'is_free' => $free,
            'is_preview' => false,
            'is_published' => true,
        ]);

        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgram($text), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        return $lesson->refresh();
    }

    private function studentInGroupOf(Course $course): User
    {
        $group = Group::create(['name' => 'Группа '.$course->id]);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        return $student->refresh();
    }

    /** Минимальный ответ Deepgram: слова со временем, предложение закрыто точкой. */
    private function deepgram(string $text): array
    {
        $words = [];
        $start = 41.0;
        foreach (explode(' ', $text) as $word) {
            $words[] = [
                'word' => $word,
                'punctuated_word' => $word,
                'start' => $start,
                'end' => $start + 0.4,
            ];
            $start += 0.5;
        }

        return ['results' => ['channels' => [['alternatives' => [['words' => $words]]]]]];
    }
}
