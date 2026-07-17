<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ResolveVisitorGeoJob;
use App\Models\SupportConversation;
use App\Services\Support\VisitorGeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1196 — Jivo-паритет Pillar 1: контекст посетителя (IP / страница входа /
 * referrer) фиксируется при первом сообщении; гео резолвится асинхронно и только
 * за флагом support_visitor_geo; куратор видит «город, страна».
 */
class SupportVisitorGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_guest_message_captures_ip_entry_url_and_referrer(): void
    {
        $this->postJson(
            '/chat/message',
            ['text' => 'Здравствуйте', 'page' => 'https://samskrte.ru/kursy/sanskrit'],
            ['referer' => 'https://yandex.ru/search'],
        )->assertOk();

        $thread = SupportConversation::firstOrFail();
        $this->assertNotNull($thread->visitor_ip);
        $this->assertSame('https://samskrte.ru/kursy/sanskrit', $thread->entry_url);
        $this->assertSame('https://yandex.ru/search', $thread->referrer);
    }

    public function test_visitor_context_is_fixed_at_thread_start_not_overwritten(): void
    {
        $this->postJson('/chat/message', ['text' => 'Раз', 'page' => 'https://samskrte.ru/a']);
        $this->postJson('/chat/message', ['text' => 'Два', 'page' => 'https://samskrte.ru/b']);

        $thread = SupportConversation::firstOrFail();
        $this->assertSame('https://samskrte.ru/a', $thread->entry_url, 'entry_url фиксируется первым сообщением');
    }

    public function test_geo_job_is_not_dispatched_when_flag_is_off(): void
    {
        Queue::fake();
        config()->set('features.support_visitor_geo', false);

        $this->postJson('/chat/message', ['text' => 'Привет'])->assertOk();

        Queue::assertNotPushed(ResolveVisitorGeoJob::class);
    }

    public function test_geo_job_is_dispatched_when_flag_is_on(): void
    {
        Queue::fake();
        config()->set('features.support_visitor_geo', true);

        // Публичный IP через X-Forwarded-For нам не доверенный прокси — используем
        // серверный REMOTE_ADDR: подставляем публичный адрес окружением сервера.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/chat/message', ['text' => 'Привет'])
            ->assertOk();

        Queue::assertPushed(ResolveVisitorGeoJob::class, function (ResolveVisitorGeoJob $job) {
            return $job->ip === '203.0.113.7';
        });
    }

    public function test_resolver_null_driver_returns_null(): void
    {
        config()->set('support_geo.driver', 'null');

        $this->assertNull(app(VisitorGeoResolver::class)->resolve('203.0.113.7'));
    }

    public function test_resolver_skips_private_ip(): void
    {
        config()->set('support_geo.driver', 'ipapi');

        $this->assertNull(app(VisitorGeoResolver::class)->resolve('192.168.1.10'));
    }

    public function test_resolver_cloudflare_driver_uses_request_hints(): void
    {
        config()->set('support_geo.driver', 'cloudflare');

        $geo = app(VisitorGeoResolver::class)->resolve('203.0.113.7', [
            'city' => 'Moscow',
            'region' => 'Moscow',
            'country' => 'RU',
        ]);

        $this->assertSame('Moscow', $geo['city']);
        $this->assertSame('RU', $geo['country']);
    }

    public function test_resolver_ipapi_driver_parses_city(): void
    {
        config()->set('support_geo.driver', 'ipapi');
        Http::fake([
            '*' => Http::response([
                'status' => 'success',
                'country' => 'Russia',
                'countryCode' => 'RU',
                'regionName' => 'St.-Petersburg',
                'city' => 'Saint Petersburg',
            ]),
        ]);

        $geo = app(VisitorGeoResolver::class)->resolve('203.0.113.7');

        $this->assertSame('Saint Petersburg', $geo['city']);
        $this->assertSame('RU', $geo['country']);
    }

    public function test_job_writes_geo_and_location_label_to_thread(): void
    {
        config()->set('support_geo.driver', 'cloudflare');

        $thread = SupportConversation::create([
            'guest_token' => 'tok-'.uniqid(),
            'status' => SupportConversation::STATUS_OPEN,
            'visitor_ip' => '203.0.113.7',
        ]);

        (new ResolveVisitorGeoJob($thread->id, '203.0.113.7', [
            'city' => 'Kazan',
            'country' => 'RU',
        ]))->handle(app(VisitorGeoResolver::class));

        $thread->refresh();
        $this->assertSame('Kazan', $thread->visitor_city);
        $this->assertNotNull($thread->visitor_geo_resolved_at);
        $this->assertSame('Kazan, RU', $thread->locationLabel());
    }

    public function test_job_is_idempotent_on_already_resolved_thread(): void
    {
        config()->set('support_geo.driver', 'cloudflare');

        $thread = SupportConversation::create([
            'guest_token' => 'tok-'.uniqid(),
            'status' => SupportConversation::STATUS_OPEN,
            'visitor_ip' => '203.0.113.7',
            'visitor_city' => 'Kazan',
            'visitor_geo_resolved_at' => now()->subHour(),
        ]);

        (new ResolveVisitorGeoJob($thread->id, '203.0.113.7', [
            'city' => 'Moscow',
        ]))->handle(app(VisitorGeoResolver::class));

        $this->assertSame('Kazan', $thread->refresh()->visitor_city, 'уже разрешённый тред не перезаписывается');
    }

    public function test_location_label_is_null_without_geo(): void
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-'.uniqid(),
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertNull($thread->locationLabel());
    }
}
