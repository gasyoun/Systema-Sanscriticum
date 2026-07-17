<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Http\Middleware\CaptureAttribution;
use App\Models\Lead;
use App\Models\SupportConversation;
use App\Services\Support\SupportLeadCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1199 — Jivo-паритет S4: необязательный телефон/почта из веб-виджета →
 * Lead-строка. Тот же паттерн, что NewsletterSubscriptionService (H324):
 * UTM из CaptureAttribution-сессии, dedup по email, идемпотентно на тред.
 */
class SupportLeadCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private function thread(array $attrs = []): SupportConversation
    {
        return SupportConversation::create(array_merge([
            'guest_token' => 'g-'.uniqid(),
            'guest_name' => 'Гость',
            'status' => SupportConversation::STATUS_OPEN,
        ], $attrs));
    }

    public function test_capture_with_email_writes_lead_and_marks_thread(): void
    {
        $thread = $this->thread(['visitor_ip' => '1.2.3.4', 'referrer' => 'https://example.com/x']);

        app(SupportLeadCaptureService::class)->capture($thread, 'Иван', 'ivan@example.com', null);

        $this->assertDatabaseHas('leads', [
            'email' => 'ivan@example.com',
            'name' => 'Иван',
            'ip_address' => '1.2.3.4',
            'referrer' => 'https://example.com/x',
        ]);

        $thread->refresh();
        $this->assertNotNull($thread->lead_id);
        $this->assertSame('ivan@example.com', $thread->contact_email);
        $this->assertNull($thread->contact_phone);
        $this->assertNotNull($thread->lead_captured_at);
    }

    public function test_capture_with_phone_only_writes_lead_without_email(): void
    {
        $thread = $this->thread();

        app(SupportLeadCaptureService::class)->capture($thread, 'Пётр', null, '+79990001122');

        $this->assertDatabaseHas('leads', [
            'contact' => '+79990001122',
            'email' => null,
        ]);
        $this->assertSame('+79990001122', $thread->fresh()->contact_phone);
    }

    public function test_capture_reuses_utm_from_attribution_session(): void
    {
        session([CaptureAttribution::SESSION_KEY => [
            'utm_source' => 'vk', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer',
        ]]);
        $thread = $this->thread();

        app(SupportLeadCaptureService::class)->capture($thread, null, 'utm@example.com', null);

        $this->assertDatabaseHas('leads', [
            'email' => 'utm@example.com',
            'utm_source' => 'vk',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer',
        ]);
    }

    public function test_capture_is_idempotent_per_thread(): void
    {
        $thread = $this->thread();

        app(SupportLeadCaptureService::class)->capture($thread, null, 'first@example.com', null);
        $firstLeadId = $thread->fresh()->lead_id;

        // Второй вызов на тот же тред (второе сообщение с другим контактом) не
        // переписывает уже захваченный лид.
        app(SupportLeadCaptureService::class)->capture($thread->fresh(), null, 'second@example.com', null);

        $this->assertSame($firstLeadId, $thread->fresh()->lead_id);
        $this->assertSame('first@example.com', $thread->fresh()->contact_email);
        $this->assertDatabaseMissing('leads', ['email' => 'second@example.com']);
        $this->assertSame(1, Lead::count());
    }

    public function test_capture_is_noop_when_both_contacts_blank(): void
    {
        $thread = $this->thread();

        app(SupportLeadCaptureService::class)->capture($thread, 'Аноним', '  ', null);

        $this->assertSame(0, Lead::count());
        $this->assertNull($thread->fresh()->lead_id);
        $this->assertNull($thread->fresh()->lead_captured_at);
    }

    public function test_repeat_email_updates_existing_lead_not_duplicate(): void
    {
        Lead::create(['name' => 'Old', 'contact' => 'dup@example.com', 'email' => 'dup@example.com', 'utm_content' => '[webchat]']);

        $thread = $this->thread();
        app(SupportLeadCaptureService::class)->capture($thread, 'New Name', 'dup@example.com', null);

        $this->assertSame(1, Lead::where('email', 'dup@example.com')->count());
    }
}
