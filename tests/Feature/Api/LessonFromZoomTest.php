<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonFromZoomTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.lesson_sync.secret', self::SECRET);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $courseId, array $overrides = []): array
    {
        return array_merge([
            'course_id' => $courseId,
            'title' => 'Грамматика санскрита №53',
            'lesson_date' => '2026-05-25T10:00:00Z',
            'block_number' => 14,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'rutube_url' => 'https://rutube.ru/play/embed/4cedc8c6e07796aac6e106cdb9ae6b52/?p=izxR6WxaesuSH8NDiEda7A',
            'topic' => 'Углублённая морфология',
            'duration_minutes' => 91,
        ], $overrides);
    }

    /** @test */
    public function rejects_request_without_secret(): void
    {
        $course = Course::factory()->create();

        $this->postJson('/api/lessons/from-zoom', $this->payload($course->id))
            ->assertStatus(401);

        $this->assertDatabaseCount('lessons', 0);
    }

    /** @test */
    public function rejects_request_with_wrong_secret(): void
    {
        $course = Course::factory()->create();

        $this->withHeader('X-Secret-Key', 'nope')
            ->postJson('/api/lessons/from-zoom', $this->payload($course->id))
            ->assertStatus(401);
    }

    /** @test */
    public function creates_published_paid_lesson_and_preserves_private_rutube_token(): void
    {
        $course = Course::factory()->create();

        $response = $this->withHeader('X-Secret-Key', self::SECRET)
            ->postJson('/api/lessons/from-zoom', $this->payload($course->id));

        $response->assertOk()
            ->assertJson(['status' => 'success', 'created' => true]);

        $lesson = Lesson::firstOrFail();

        $this->assertEquals($course->id, $lesson->course_id);
        $this->assertSame(14, $lesson->block_number);
        $this->assertTrue($lesson->is_published);
        $this->assertFalse($lesson->is_free);
        // youtube нормализован к короткой форме
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $lesson->youtube_url);
        // приватный токен ?p= сохранён
        $this->assertStringContainsString('p=izxR6WxaesuSH8NDiEda7A', $lesson->rutube_url);
        $this->assertStringContainsString('4cedc8c6e07796aac6e106cdb9ae6b52', $lesson->rutube_url);
        // минуты сконвертированы в секунды
        $this->assertSame(91 * 60, $lesson->duration_seconds);
    }

    /** @test */
    public function is_idempotent_by_course_and_date(): void
    {
        $course = Course::factory()->create();

        $this->withHeader('X-Secret-Key', self::SECRET)
            ->postJson('/api/lessons/from-zoom', $this->payload($course->id))
            ->assertOk();

        $this->withHeader('X-Secret-Key', self::SECRET)
            ->postJson('/api/lessons/from-zoom', $this->payload($course->id, ['title' => 'Обновлённое название']))
            ->assertOk()
            ->assertJson(['created' => false]);

        $this->assertDatabaseCount('lessons', 1);
        $this->assertSame('Обновлённое название', Lesson::firstOrFail()->title);
    }

    /** @test */
    public function validates_course_must_exist(): void
    {
        $this->withHeader('X-Secret-Key', self::SECRET)
            ->postJson('/api/lessons/from-zoom', $this->payload(999999))
            ->assertStatus(422);
    }
}
