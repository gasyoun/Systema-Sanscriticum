<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\PublishSocialPostJob;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\LectureClip;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PublishSocialPostJob (H1548, Wave 2). VERIFICATION criteria B2/B3/B4.
 */
class PublishSocialPostJobTest extends TestCase
{
    use RefreshDatabase;

    private function socialCandidate(array $overrides = []): ContentCandidate
    {
        $lesson = Lesson::factory()->for(Course::factory())->create();
        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Клип',
            'vk_video_id' => '456239017',
            'vk_owner_id' => '-12345',
        ]);

        return ContentCandidate::create(array_merge([
            'type' => ContentCandidate::TYPE_SOCIAL_POST,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'lesson_id' => $lesson->id,
            'lecture_clip_id' => $clip->id,
            'title' => 'Пост про клип',
            'body' => 'Смотрите отрывок лекции!',
            'quote' => 'Санскрит — древний язык.',
            'channel_target' => 'vk_wall',
        ], $overrides));
    }

    public function test_noop_when_pilot_flag_off(): void
    {
        config(['features.content_auto_publish_pilot' => false]);
        Http::fake();

        $candidate = $this->socialCandidate();
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $candidate->fresh()->status);
    }

    public function test_posts_to_mocked_vk_and_tg_when_flag_on(): void
    {
        config([
            'features.content_auto_publish_pilot' => true,
            'services.n8n.social_post_webhook' => 'https://n8n.example/webhook/social-post',
            'services.n8n.social_post_secret' => 'out-secret',
        ]);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);

        $candidate = $this->socialCandidate();
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://n8n.example/webhook/social-post'
            && $request->hasHeader('X-Webhook-Secret', 'out-secret')
            && $request['action'] === 'social_post'
            && $request['content_candidate_id'] === $candidate->id
            && $request['text_vk'] === 'Смотрите отрывок лекции!'
            && $request['text_tg'] === 'Смотрите отрывок лекции!'
            && $request['vk_video_id'] === '456239017'
            && $request['vk_owner_id'] === '-12345');

        $fresh = $candidate->fresh();
        $this->assertSame(ContentCandidate::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_quote_policy_hard_fails_and_marks_failed(): void
    {
        config([
            'features.content_auto_publish_pilot' => true,
            'services.n8n.social_post_webhook' => 'https://n8n.example/webhook/social-post',
        ]);
        Http::fake();

        $candidate = $this->socialCandidate(['quote' => 'Первое. Второе. Третье.']);
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(ContentCandidate::STATUS_FAILED, $candidate->fresh()->status);
    }

    public function test_skips_when_not_accepted(): void
    {
        config(['features.content_auto_publish_pilot' => true]);
        Http::fake();

        $candidate = $this->socialCandidate(['status' => ContentCandidate::STATUS_DRAFT]);
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertNothingSent();
    }

    public function test_skips_already_published(): void
    {
        config([
            'features.content_auto_publish_pilot' => true,
            'services.n8n.social_post_webhook' => 'https://n8n.example/webhook/social-post',
        ]);
        Http::fake();

        $candidate = $this->socialCandidate(['status' => ContentCandidate::STATUS_PUBLISHED]);
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertNothingSent();
    }

    public function test_skips_when_webhook_not_configured(): void
    {
        config(['features.content_auto_publish_pilot' => true, 'services.n8n.social_post_webhook' => null]);
        Http::fake();

        $candidate = $this->socialCandidate();
        (new PublishSocialPostJob($candidate->id))->handle();

        Http::assertNothingSent();
    }
}
