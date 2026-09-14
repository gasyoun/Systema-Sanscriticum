<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendLeadMessenger;
use App\Mail\LeadAdHocMail;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\MarketingSetting;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendLeadMessengerJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'bot', 'tg_bot_token' => 'fake-tg',
            'vk_group_screen_name' => 'g', 'vk_access_token' => 'fake-vk',
            'max_bot_username' => 'mbot', 'max_bot_token' => 'fake-max',
        ]);
    }

    private function makeLead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Лид', 'contact' => '+1', 'email' => 'a@b.c',
        ], $attrs));
    }

    /** @test */
    public function sends_telegram_text_and_logs_note(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $lead = $this->makeLead(['telegram_chat_id' => 12345]);

        (new SendLeadMessenger($lead->id, 'Намасте, Лид!', ['telegram']))
            ->handle(app(DeliveryChannelManager::class));

        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage'));
        $this->assertDatabaseHas('lead_notes', [
            'lead_id' => $lead->id,
            'type' => LeadNote::TYPE_DM,
            'channel' => 'telegram',
        ]);
    }

    /** @test */
    public function skips_channel_without_collected_id(): void
    {
        Http::fake();

        // У лида нет vk_user_id — VK-канал пропускается, ничего не шлём и не логируем.
        $lead = $this->makeLead(['telegram_chat_id' => null, 'vk_user_id' => null]);

        (new SendLeadMessenger($lead->id, 'Привет', ['vk']))
            ->handle(app(DeliveryChannelManager::class));

        Http::assertNothingSent();
        $this->assertDatabaseCount('lead_notes', 0);
    }

    /** @test */
    public function sends_email_with_subject_and_logs_note(): void
    {
        Mail::fake();
        Http::fake();

        $lead = $this->makeLead(['email' => 'lead@example.com']);

        (new SendLeadMessenger($lead->id, "Намасте, Лид!\nВторая строка", ['email'], null, 'Тема письма'))
            ->handle(app(DeliveryChannelManager::class));

        Mail::assertQueued(LeadAdHocMail::class, fn (LeadAdHocMail $m) => $m->hasTo('lead@example.com')
            && $m->subjectLine === 'Тема письма'
            && str_contains($m->bodyText, '<br />'));
        Http::assertNothingSent();
        $this->assertDatabaseHas('lead_notes', [
            'lead_id' => $lead->id,
            'type' => LeadNote::TYPE_EMAIL,
            'channel' => 'email',
            'subject' => 'Тема письма',
        ]);
    }

    /** @test */
    public function skips_email_without_subject_or_with_invalid_address(): void
    {
        Mail::fake();

        $noSubject = $this->makeLead(['email' => 'lead@example.com']);
        (new SendLeadMessenger($noSubject->id, 'Привет', ['email']))
            ->handle(app(DeliveryChannelManager::class));

        $badEmail = $this->makeLead(['email' => 'не-адрес']);
        (new SendLeadMessenger($badEmail->id, 'Привет', ['email'], null, 'Тема'))
            ->handle(app(DeliveryChannelManager::class));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('lead_notes', 0);
    }

    /** @test */
    public function skips_when_lead_missing(): void
    {
        Http::fake();
        (new SendLeadMessenger(99999, 'Привет', ['telegram']))
            ->handle(app(DeliveryChannelManager::class));
        Http::assertNothingSent();
    }
}
