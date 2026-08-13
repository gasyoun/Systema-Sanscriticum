<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\LifecycleAutomation;
use App\Jobs\SendCampaignRecipient;
use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Course;
use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Payment;
use App\Models\User;
use App\Services\Crm\Lifecycle\LifecycleCampaignPreparer;
use App\Services\Crm\Lifecycle\LifecycleEligibility;
use App\Services\Email\CampaignHtmlRenderer;
use App\Services\Email\CampaignSender;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2484 — prepare is draft-only; retries cannot double-send; flag defaults OFF.
 */
class LifecyclePrepareAndSendTest extends TestCase
{
    use RefreshDatabase;

    private function stalePendingUser(): User
    {
        $user = User::factory()->create([
            'email' => 'stale@example.test',
            'wants_email_announcements' => true,
        ]);
        $course = Course::factory()->create(['is_active' => true]);
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ]));
        $payment->forceFill(['created_at' => now()->subHours(5)])->saveQuietly();

        return $user;
    }

    /** @test */
    public function flag_defaults_off_and_page_is_hidden(): void
    {
        $this->assertFalse((bool) config('features.crm_lifecycle_automation'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->assertFalse(LifecycleAutomation::canAccess());
        $this->assertFalse(LifecycleAutomation::shouldRegisterNavigation());
    }

    /** @test */
    public function artisan_dry_run_writes_nothing_even_when_flag_on(): void
    {
        config(['features.crm_lifecycle_automation' => true]);
        $this->stalePendingUser();

        $code = Artisan::call('crm:lifecycle-prepare', ['--json' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(0, Campaign::query()->count());
        $payload = json_decode(Artisan::output(), true);
        $this->assertTrue($payload['dry_run']);
        $checkout = collect($payload['rules'])->firstWhere('rule', LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT);
        $this->assertSame(1, $checkout['eligible']);
    }

    /** @test */
    public function apply_refuses_when_flag_off(): void
    {
        $this->stalePendingUser();

        $code = Artisan::call('crm:lifecycle-prepare', ['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertSame(0, Campaign::query()->count());
    }

    /** @test */
    public function apply_creates_one_draft_and_never_dispatches_send(): void
    {
        Queue::fake();
        config(['features.crm_lifecycle_automation' => true]);
        $this->stalePendingUser();

        $first = app(LifecycleCampaignPreparer::class)->run(
            apply: true,
            onlyRule: LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT,
            withFollowUps: false,
        );
        $second = app(LifecycleCampaignPreparer::class)->run(
            apply: true,
            onlyRule: LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT,
            withFollowUps: false,
        );

        $this->assertSame(1, Campaign::query()->count());
        $campaign = Campaign::query()->first();
        $this->assertSame(Campaign::STATUS_DRAFT, $campaign->status);
        $this->assertSame($first['rules'][0]['campaign_id'], $second['rules'][0]['campaign_id']);
        $this->assertSame('lifecycle', $campaign->segment['type']);
        Queue::assertNothingPushed();
        $this->assertSame(0, CampaignRecipient::query()->count());
    }

    /** @test */
    public function apply_upserts_follow_up_on_open_deal_once(): void
    {
        config(['features.crm_lifecycle_automation' => true]);
        $user = $this->stalePendingUser();
        Deal::factory()->create(['user_id' => $user->id, 'closed_at' => null]);

        $preparer = app(LifecycleCampaignPreparer::class);
        $preparer->run(true, LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT, true);
        $preparer->run(true, LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT, true);

        $this->assertSame(1, FollowUpTask::query()->count());
        $this->assertStringContainsString('[lifecycle:uncompleted_checkout:v1]', FollowUpTask::query()->first()->note);
    }

    /** @test */
    public function send_retry_does_not_create_a_second_recipient_or_job(): void
    {
        Queue::fake();
        config([
            'features.email_campaigns' => true,
            'features.crm_lifecycle_automation' => true,
        ]);
        $user = $this->stalePendingUser();
        $prepared = app(LifecycleCampaignPreparer::class)->run(
            apply: true,
            onlyRule: LifecycleEligibility::RULE_UNCOMPLETED_CHECKOUT,
            withFollowUps: false,
        );
        $campaign = Campaign::query()->findOrFail($prepared['rules'][0]['campaign_id']);

        $sender = app(CampaignSender::class);
        $sender->send($campaign);
        $sender->send($campaign->fresh());

        $this->assertSame(1, $campaign->fresh()->recipients()->count());
        $this->assertSame(mb_strtolower($user->email), $campaign->fresh()->recipients()->first()->email);
        Queue::assertPushed(SendCampaignRecipient::class, 1);
    }

    /** @test */
    public function recipient_job_retry_does_not_mail_twice(): void
    {
        Mail::fake();
        config(['features.email_campaigns' => true]);
        $campaign = Campaign::query()->create([
            'subject' => 'once',
            'body_html' => '<p>hi</p>',
            'segment' => ['type' => 'all_subscribers'],
            'status' => Campaign::STATUS_SENDING,
        ]);
        $recipient = $campaign->recipients()->create([
            'email' => 'once@example.test',
            'pixel_token' => 'tok-once',
        ]);

        $job = new SendCampaignRecipient($recipient->id);
        $job->handle(app(CampaignHtmlRenderer::class));
        $job->handle(app(CampaignHtmlRenderer::class));

        Mail::assertQueued(CampaignMail::class, 1);
        $this->assertNotNull($recipient->fresh()->sent_at);
    }
}
