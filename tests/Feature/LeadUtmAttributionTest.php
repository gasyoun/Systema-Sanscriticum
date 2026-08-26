<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H3576 §A — UTM-атрибуция лидов лендингов: форма лендинга передаёт
 * query-строку страницы в action (см. promo/blocks/*.blade.php), контроллер
 * добирает пустые utm-поля из query POST-запроса. Пост-сценарий: ссылка в
 * TG-канале несёт ?utm_source=telegram&utm_campaign=... — каждый лид обязан
 * прийти с метками, иначе разрез «откуда пришёл» не собрать.
 */
class LeadUtmAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function payload(int $landingId): array
    {
        return [
            'landing_page_id' => $landingId,
            'contact' => '+79990000000',
            'is_promo_agreed' => '1',
        ];
    }

    private function landing(): LandingPage
    {
        return LandingPage::create(['title' => 'L', 'slug' => 'utm-'.uniqid()]);
    }

    /** @test */
    public function utm_from_the_page_query_lands_on_the_lead_when_body_omits_it(): void
    {
        $landing = $this->landing();

        // В проде query попадает в POST через form action — повторяем это.
        $this->post(route('leads.store').'?utm_source=telegram&utm_medium=channel&utm_campaign=anons-osenn-2026&utm_content=post601', $this->payload($landing->id))
            ->assertRedirect();

        $this->travel(6)->seconds();

        $lead = Lead::where('landing_page_id', $landing->id)->firstOrFail();
        $this->assertSame('telegram', $lead->utm_source);
        $this->assertSame('channel', $lead->utm_medium);
        $this->assertSame('anons-osenn-2026', $lead->utm_campaign);
        $this->assertSame('post601', $lead->utm_content);
    }

    /** @test */
    public function query_never_overrides_body_and_empty_query_leaves_nulls(): void
    {
        $landing = $this->landing();

        // Тело сильнее query: присланный utm_source не затирается.
        $this->post(route('leads.store').'?utm_source=querysrc&utm_campaign=querycmp', [
            'landing_page_id' => $landing->id,
            'contact' => '+79990000001',
            'utm_source' => 'bodysrc',
            'is_promo_agreed' => '1',
        ])->assertRedirect();

        $lead = Lead::where('landing_page_id', $landing->id)->firstOrFail();
        $this->assertSame('bodysrc', $lead->utm_source);
        $this->assertSame('querycmp', $lead->utm_campaign);

        // Чистый POST без query и без utm в теле — null'ы (не пустые строки).
        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->travel(6)->seconds();

        $this->post(route('leads.store'), [
            'landing_page_id' => $landing->id,
            'contact' => '+79990000002',
            'is_promo_agreed' => '1',
        ])->assertRedirect();

        $plain = Lead::where('contact', '+79990000002')->firstOrFail();
        $this->assertNull($plain->utm_source);
        $this->assertNull($plain->utm_campaign);
    }

    /** @test */
    public function form_name_prefix_still_composes_over_the_resolved_utm_content(): void
    {
        $landing = $this->landing();

        $this->post(route('leads.store').'?utm_content=post601', $this->payload($landing->id) + ['form_name' => 'hero'])
            ->assertRedirect();

        $this->travel(6)->seconds();

        $lead = Lead::where('landing_page_id', $landing->id)->firstOrFail();
        $this->assertSame('[hero] post601', $lead->utm_content);
    }
}
