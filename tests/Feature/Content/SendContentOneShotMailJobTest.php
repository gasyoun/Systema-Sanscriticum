<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\SendContentOneShotMailJob;
use App\Mail\ContentDigestMail;
use App\Models\ContentCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendContentOneShotMailJobTest extends TestCase
{
    use RefreshDatabase;

    private function digestCandidate(array $overrides = []): ContentCandidate
    {
        return ContentCandidate::create(array_merge([
            'type' => ContentCandidate::TYPE_EMAIL_BLAST,
            'status' => ContentCandidate::STATUS_ACCEPTED,
            'title' => 'Дайджест недели',
            'body' => 'Смотрите новые фрагменты!',
            'channel_target' => 'email',
        ], $overrides));
    }

    public function test_noop_when_flag_off(): void
    {
        config(['features.content_email_oneshot' => false]);
        Mail::fake();
        User::factory()->create(['newsletter_subscribed_at' => now()]);

        $candidate = $this->digestCandidate();
        (new SendContentOneShotMailJob($candidate->id))->handle();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $candidate->fresh()->status);
    }

    public function test_sends_to_newsletter_segment_when_flag_on(): void
    {
        config(['features.content_email_oneshot' => true]);
        Mail::fake();

        $subscriber = User::factory()->create(['newsletter_subscribed_at' => now(), 'email' => 'subscriber@example.com']);
        User::factory()->create(['newsletter_subscribed_at' => null, 'email' => 'not-subscribed@example.com']);

        $candidate = $this->digestCandidate();
        (new SendContentOneShotMailJob($candidate->id))->handle();

        Mail::assertQueued(ContentDigestMail::class, fn ($mail) => $mail->hasTo($subscriber->email));
        Mail::assertQueued(ContentDigestMail::class, 1);

        $fresh = $candidate->fresh();
        $this->assertSame(ContentCandidate::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_at);
        $this->assertSame(1, $fresh->meta['recipient_count']);
    }

    public function test_noop_when_segment_empty(): void
    {
        config(['features.content_email_oneshot' => true]);
        Mail::fake();

        $candidate = $this->digestCandidate();
        (new SendContentOneShotMailJob($candidate->id))->handle();

        Mail::assertNothingQueued();
        $this->assertSame(ContentCandidate::STATUS_ACCEPTED, $candidate->fresh()->status);
    }

    public function test_skips_when_not_accepted(): void
    {
        config(['features.content_email_oneshot' => true]);
        Mail::fake();
        User::factory()->create(['newsletter_subscribed_at' => now()]);

        $candidate = $this->digestCandidate(['status' => ContentCandidate::STATUS_DRAFT]);
        (new SendContentOneShotMailJob($candidate->id))->handle();

        Mail::assertNothingQueued();
    }
}
