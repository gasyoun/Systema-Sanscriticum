<?php

declare(strict_types=1);

namespace Tests\Feature\LectureClips;

use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inbound n8n callback → LectureClip rows (H1452 W4).
 * No real VK / ffmpeg — payload is mocked HTTP.
 */
class LectureClipCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-clip-callback-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.clip_marketing' => true,
            'services.n8n.clip_callback_secret' => self::SECRET,
        ]);
    }

    private function lesson(array $overrides = []): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create($overrides);
    }

    private function hit(array $payload, ?string $secret = self::SECRET)
    {
        $headers = $secret !== null ? ['X-Webhook-Secret' => $secret] : [];

        return $this->withHeaders($headers)
            ->postJson('/api/webhooks/lecture-clip-callback', $payload);
    }

    public function test_callback_creates_lecture_clip_rows(): void
    {
        $lesson = $this->lesson(['is_published' => true]);

        $this->hit([
            'lesson_id' => $lesson->id,
            'clips' => [
                [
                    'start_seconds' => 0,
                    'end_seconds' => 90,
                    'title' => 'Фрагмент 1',
                    'vk_video_id' => '456239017',
                    'vk_owner_id' => '-12345',
                ],
                [
                    'start_seconds' => 90,
                    'end_seconds' => 180,
                    'title' => 'Фрагмент 2',
                    'vk_video_id' => '456239018',
                    'vk_owner_id' => '-12345',
                ],
            ],
        ])->assertOk()->assertJson(['ok' => true, 'received' => 2, 'created' => 2]);

        $this->assertDatabaseCount('lecture_clips', 2);
        $this->assertDatabaseHas('lecture_clips', [
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'vk_video_id' => '456239017',
            'is_free' => false,
        ]);
    }

    public function test_callback_is_idempotent_on_span_keys(): void
    {
        $lesson = $this->lesson(['is_published' => true]);
        $payload = [
            'lesson_id' => $lesson->id,
            'clips' => [[
                'start_seconds' => 10,
                'end_seconds' => 100,
                'title' => 'Same span',
                'vk_video_id' => '1',
                'vk_owner_id' => '-1',
            ]],
        ];

        $this->hit($payload)->assertOk()->assertJson(['created' => 1]);
        $this->hit($payload)->assertOk()->assertJson(['created' => 0]);
        $this->assertSame(1, LectureClip::query()->count());
    }

    public function test_callback_404_when_flag_off(): void
    {
        config(['features.clip_marketing' => false]);
        $lesson = $this->lesson();

        $this->hit([
            'lesson_id' => $lesson->id,
            'clips' => [[
                'start_seconds' => 0,
                'end_seconds' => 30,
                'title' => 'x',
            ]],
        ])->assertNotFound();

        $this->assertDatabaseCount('lecture_clips', 0);
    }

    public function test_callback_rejects_bad_secret(): void
    {
        $lesson = $this->lesson();

        $this->hit([
            'lesson_id' => $lesson->id,
            'clips' => [[
                'start_seconds' => 0,
                'end_seconds' => 30,
                'title' => 'x',
            ]],
        ], secret: 'wrong')->assertForbidden();
    }

    public function test_staff_marked_is_free_is_the_only_free_surface(): void
    {
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 60,
            'title' => 'paid',
            'is_free' => false,
        ]);
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 60,
            'end_seconds' => 120,
            'title' => 'free-1',
            'is_free' => true,
        ]);
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 120,
            'end_seconds' => 180,
            'title' => 'free-2',
            'is_free' => true,
        ]);

        $free = LectureClip::query()->free()->pluck('title')->all();
        $this->assertSame(['free-1', 'free-2'], $free);
        $this->assertCount(1, LectureClip::query()->where('is_free', false)->get());
    }
}
