<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LeadWebinarRecordingMail;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Leads\LeadStepMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Письмо лиду со ссылкой на запись вебинара: мейлер (шаг webinar_recording,
 * идемпотентность, gate на webinar_recording_url) и команда-планировщик.
 */
class WebinarRecordingDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function landing(array $overrides = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'L',
            'slug' => 'l-'.uniqid(),
            'is_active' => true,
            'webinar_label' => 'Бесплатный вебинар',
            'webinar_recording_url' => 'https://youtu.be/rec1',
            'webinar_date' => now()->subDay(),
        ], $overrides));
    }

    private function lead(LandingPage $landing, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Иван',
            'contact' => '+70000000000',
            'email' => 'lead'.uniqid().'@example.com',
            'landing_page_id' => $landing->id,
        ], $overrides));
    }

    /** @test */
    public function mailer_sends_recording_and_is_idempotent(): void
    {
        $lead = $this->lead($this->landing());
        $mailer = app(LeadStepMailer::class);

        $this->assertSame('sent', $mailer->deliver($lead, 'webinar_recording'));
        $this->assertDatabaseHas('lead_step_emails', ['lead_id' => $lead->id, 'step' => 'webinar_recording']);
        Mail::assertQueued(LeadWebinarRecordingMail::class, 1);

        $this->assertSame('already_sent', $mailer->deliver($lead, 'webinar_recording'));
        Mail::assertQueued(LeadWebinarRecordingMail::class, 1);
    }

    /** @test */
    public function mailer_not_eligible_without_recording_url(): void
    {
        $lead = $this->lead($this->landing(['webinar_recording_url' => null]));

        $this->assertSame('not_eligible', app(LeadStepMailer::class)->deliver($lead, 'webinar_recording'));
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('lead_step_emails', 0);
    }

    /** @test */
    public function mailer_no_email_when_lead_has_no_email(): void
    {
        $lead = $this->lead($this->landing(), ['email' => null]);

        $this->assertSame('no_email', app(LeadStepMailer::class)->deliver($lead, 'webinar_recording'));
        Mail::assertNothingQueued();
    }

    /** @test */
    public function command_delivers_to_leads_with_email_only_once(): void
    {
        $landing = $this->landing();
        $this->lead($landing);
        $this->lead($landing);
        $this->lead($landing, ['email' => null]); // без email — пропускаем

        $this->artisan('webinar:deliver-recordings')->assertSuccessful();
        Mail::assertQueued(LeadWebinarRecordingMail::class, 2);

        // Повторный прогон — новых писем нет.
        $this->artisan('webinar:deliver-recordings')->assertSuccessful();
        Mail::assertQueued(LeadWebinarRecordingMail::class, 2);
    }

    /** @test */
    public function command_skips_landing_without_recording_url(): void
    {
        $landing = $this->landing(['webinar_recording_url' => null]);
        $this->lead($landing);

        $this->artisan('webinar:deliver-recordings')->assertSuccessful();
        Mail::assertNothingQueued();
    }
}
