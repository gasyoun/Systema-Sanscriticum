<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LeadWebinarInviteMail;
use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Вебхук «лид дошёл до шага бота» (n8n → Laravel): секрет, поиск лида по
 * telegram_chat_id/token, отправка письма за шаг и идемпотентность.
 */
class LeadStepWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-n8n-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.n8n.lead_step_secret' => self::SECRET]);
    }

    private function landing(array $overrides = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'L',
            'slug' => 'l-'.uniqid(),
            'is_active' => true,
            'webinar_url' => 'https://zoom.us/j/1',
            'webinar_label' => 'Бесплатный вебинар',
        ], $overrides));
    }

    private function lead(LandingPage $landing, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Иван',
            'contact' => '+70000000000',
            'email' => 'lead'.uniqid().'@example.com',
            'landing_page_id' => $landing->id,
            'telegram_chat_id' => 555000,
        ], $overrides));
    }

    private function hit(array $payload, ?string $secret = self::SECRET)
    {
        return $this->withHeaders($secret !== null ? ['X-Webhook-Secret' => $secret] : [])
            ->postJson('/api/webhooks/lead-step', $payload);
    }

    /** @test */
    public function rejects_without_valid_secret(): void
    {
        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 1], secret: 'wrong')
            ->assertForbidden();
        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 1], secret: null)
            ->assertForbidden();
    }

    /** @test */
    public function sends_step_email_and_is_idempotent(): void
    {
        $lead = $this->lead($this->landing());

        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 555000])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'sent']);

        Mail::assertQueued(LeadWebinarInviteMail::class, 1);
        $this->assertDatabaseHas('lead_step_emails', ['lead_id' => $lead->id, 'step' => 'webinar_invite']);

        // Повтор (ретрай n8n) — письмо не дублируется.
        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 555000])
            ->assertOk()
            ->assertJson(['status' => 'already_sent']);

        Mail::assertQueued(LeadWebinarInviteMail::class, 1);
    }

    /** @test */
    public function finds_lead_by_token_when_no_chat_id(): void
    {
        $lead = $this->lead($this->landing(), ['telegram_chat_id' => null, 'magnet_token' => 'tok123']);

        $this->hit(['step' => 'webinar_invite', 'token' => 'tok123'])
            ->assertOk()
            ->assertJson(['status' => 'sent']);

        Mail::assertQueued(LeadWebinarInviteMail::class, 1);
    }

    /** @test */
    public function unknown_step_does_not_send(): void
    {
        $this->lead($this->landing());

        $this->hit(['step' => 'no_such_step', 'telegram_chat_id' => 555000])
            ->assertOk()
            ->assertJson(['ok' => false, 'status' => 'unknown_step']);

        Mail::assertNothingQueued();
    }

    /** @test */
    public function not_eligible_when_landing_has_no_webinar_url(): void
    {
        $this->lead($this->landing(['webinar_url' => null]));

        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 555000])
            ->assertOk()
            ->assertJson(['status' => 'not_eligible']);

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('lead_step_emails', 0);
    }

    /** @test */
    public function unknown_lead_returns_404(): void
    {
        $this->hit(['step' => 'webinar_invite', 'telegram_chat_id' => 999999])
            ->assertNotFound()
            ->assertJson(['status' => 'lead_not_found']);
    }
}
