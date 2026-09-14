<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Lecture\ClipSpanPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Расшифровка Deepgram из n8n-сценария записи занятия.
 *
 * Нарезка клипов читает единственный источник границ — `lesson.transcript_file`
 * (`ClipSpanPlanner`). На проде он был пуст у всех уроков: Deepgram-ответ уходил
 * в Google Drive и субтитры Rutube, а в LMS не попадал, поэтому «Нарезать
 * лекцию» всегда давала пустой список спанов.
 */
class LessonTranscriptIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.lesson_sync.secret', self::SECRET);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function lesson(): Lesson
    {
        return Lesson::factory()->create([
            'course_id' => Course::factory()->create()->id,
            'title' => 'Грамматика санскрита №53',
            'is_published' => true,
        ]);
    }

    /**
     * Слова Deepgram: пары «слово + таймкод», из них парсер собирает предложения.
     *
     * @return array<string, mixed>
     */
    private function deepgram(int $words = 240): array
    {
        $list = [];
        for ($i = 0; $i < $words; $i++) {
            $last = ($i % 12) === 11; // каждое 12-е слово закрывает предложение
            $list[] = [
                'word' => 'слово'.$i,
                'punctuated_word' => 'слово'.$i.($last ? '.' : ''),
                'start' => $i * 2.0,
                'end' => $i * 2.0 + 1.5,
            ];
        }

        return ['results' => ['channels' => [['alternatives' => [['words' => $list]]]]]];
    }

    public function test_it_rejects_a_request_without_the_secret(): void
    {
        $lesson = $this->lesson();

        $this->postJson("/api/lessons/{$lesson->id}/transcript", $this->deepgram())
            ->assertStatus(401);

        $this->assertNull($lesson->fresh()->transcript_file);
    }

    public function test_it_stores_the_transcript_and_makes_the_lesson_cuttable(): void
    {
        $lesson = $this->lesson();

        $response = $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson("/api/lessons/{$lesson->id}/transcript", $this->deepgram());

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'lesson_id' => $lesson->id,
                'transcript_file' => "transcripts/lesson-{$lesson->id}.json",
            ]);

        $this->assertGreaterThan(0, $response->json('sentences'));

        $lesson->refresh();
        $this->assertSame("transcripts/lesson-{$lesson->id}.json", $lesson->transcript_file);
        Storage::disk('local')->assertExists($lesson->transcript_file);

        // Ради этого всё и делается: спаны для нарезки перестают быть пустыми.
        $this->assertNotEmpty(ClipSpanPlanner::planSpans($lesson));
    }

    /** n8n оборачивает item-ы в массив — парсер это понимает, ручка тоже. */
    public function test_it_accepts_the_n8n_item_wrapper(): void
    {
        $lesson = $this->lesson();

        $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson("/api/lessons/{$lesson->id}/transcript", [
                'transcript' => [['json' => $this->deepgram()]],
            ])
            ->assertOk();

        $this->assertNotEmpty(ClipSpanPlanner::planSpans($lesson->fresh()));
    }

    /**
     * Пустой транскрипт хуже отказа: урок выглядел бы готовым к нарезке,
     * а спанов бы не было.
     */
    public function test_it_refuses_a_payload_without_words(): void
    {
        $lesson = $this->lesson();

        $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson("/api/lessons/{$lesson->id}/transcript", ['results' => ['channels' => []]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertNull($lesson->fresh()->transcript_file);
    }

    /** Повторная заливка перезаписывает тот же файл, дублей не плодит. */
    public function test_reposting_overwrites_the_same_file(): void
    {
        $lesson = $this->lesson();

        $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson("/api/lessons/{$lesson->id}/transcript", $this->deepgram(120))
            ->assertOk();

        $second = $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson("/api/lessons/{$lesson->id}/transcript", $this->deepgram(360))
            ->assertOk();

        $this->assertSame(
            "transcripts/lesson-{$lesson->id}.json",
            $second->json('transcript_file'),
        );

        $stored = json_decode(Storage::disk('local')->get("transcripts/lesson-{$lesson->id}.json"), true);
        $this->assertCount(360, $stored['results']['channels'][0]['alternatives'][0]['words']);
    }

    /** Неизвестный урок — 404, а не тихое создание файла в пустоту. */
    public function test_unknown_lesson_is_a_404(): void
    {
        $this->withHeaders(['X-Secret-Key' => self::SECRET])
            ->postJson('/api/lessons/999999/transcript', $this->deepgram())
            ->assertStatus(404);
    }
}
