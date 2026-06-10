<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendLeadMagnet;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Leads\LeadMagnetDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Выдача лид-магнита «за N минут до вебинара»: окно [старт−offset; старт],
 * поздний /start (в окне → сразу, после старта → никогда), планировщик.
 */
class LeadMagnetTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function landing(array $overrides = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'L',
            'slug' => 'l-'.uniqid(),
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/file.pdf',
        ], $overrides));
    }

    private function lead(LandingPage $landing, array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Иван',
            'contact' => '+70000000000',
            'landing_page_id' => $landing->id,
            'magnet_channel' => 'telegram',
            'telegram_chat_id' => 555000,
        ], $overrides));
    }

    /** @test */
    public function delivers_immediately_when_landing_has_no_webinar(): void
    {
        $lead = $this->lead($this->landing(['webinar_date' => null]));

        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');

        Queue::assertPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function defers_when_more_than_offset_before_start(): void
    {
        Carbon::setTestNow('2026-06-17 16:00');
        // старт 18:00, окно за 60 мин → откроется в 17:00; сейчас 16:00 — рано.
        $lead = $this->lead($this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]));

        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');

        Queue::assertNotPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function delivers_when_inside_window(): void
    {
        Carbon::setTestNow('2026-06-17 17:30');
        $lead = $this->lead($this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]));

        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');

        Queue::assertPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function delivers_within_grace_after_start(): void
    {
        // Старт 18:00, грейс +2ч → окно до 20:00. В 19:30 опоздавший ещё получает магнит.
        Carbon::setTestNow('2026-06-17 19:30');
        $lead = $this->lead($this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]));

        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');

        Queue::assertPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function does_not_deliver_after_grace_period(): void
    {
        // Старт 18:00, грейс +2ч → окно закрылось в 20:00. В 20:05 уже не выдаём.
        Carbon::setTestNow('2026-06-17 20:05');
        $lead = $this->lead($this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]));

        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');

        Queue::assertNotPushed(SendLeadMagnet::class);
    }

    /** @test */
    public function command_delivers_only_leads_in_open_window(): void
    {
        Carbon::setTestNow('2026-06-17 17:30');

        // Окно открыто (17:00–18:00): лид должен получить магнит.
        $openLanding = $this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]);
        $this->lead($openLanding);

        // Вебинар через 3 часа — окно ещё закрыто.
        $earlyLanding = $this->landing([
            'webinar_date' => '2026-06-17 20:30',
            'magnet_lead_minutes' => 60,
        ]);
        $this->lead($earlyLanding);

        // Уже выданный магнит — пропускаем.
        $this->lead($openLanding, [
            'telegram_chat_id' => 555111,
            'magnet_delivered_at' => now(),
        ]);

        $this->artisan('magnets:deliver-due')->assertSuccessful();

        Queue::assertPushed(SendLeadMagnet::class, 1);
    }

    /** @test */
    public function command_skips_leads_without_channel_id(): void
    {
        Carbon::setTestNow('2026-06-17 17:30');
        $landing = $this->landing([
            'webinar_date' => '2026-06-17 18:00',
            'magnet_lead_minutes' => 60,
        ]);
        // Лид оставил заявку, но бота ещё не запускал — нет ни одного канального id.
        $this->lead($landing, ['telegram_chat_id' => null]);

        $this->artisan('magnets:deliver-due')->assertSuccessful();

        Queue::assertNotPushed(SendLeadMagnet::class);
    }
}
