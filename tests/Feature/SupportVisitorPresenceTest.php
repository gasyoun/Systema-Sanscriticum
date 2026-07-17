<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PruneStaleVisitorPresencesJob;
use App\Jobs\ResolveVisitorPresenceGeoJob;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportVisitorPresence;
use App\Services\Support\VisitorGeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1197 — Jivo-паритет Pillar 2: проактивный монитор посетителей. Presence-beacon
 * пишет эфемерную строку присутствия ТОЛЬКО за флагом support_visitor_presence;
 * апсертит по guest_token; гео резолвится один раз при создании и только за
 * support_visitor_geo; вымётывающая джоба удаляет устаревшие; beacon возвращает
 * conversation_id, чтобы проактив куратора долетел до молчащего посетителя.
 */
class SupportVisitorPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.support_visitor_presence', true);
    }

    public function test_beacon_creates_presence_row_when_flag_on(): void
    {
        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/kursy'])
            ->assertOk()
            ->assertJson(['ok' => true, 'enabled' => true, 'conversation_id' => null]);

        $presence = SupportVisitorPresence::firstOrFail();
        $this->assertNotNull($presence->guest_token);
        $this->assertSame('https://samskrte.ru/kursy', $presence->current_url);
        $this->assertNotNull($presence->first_seen_at);
        $this->assertNotNull($presence->last_seen_at);
    }

    public function test_beacon_is_noop_when_flag_off(): void
    {
        config()->set('features.support_visitor_presence', false);

        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/kursy'])
            ->assertOk()
            ->assertJson(['ok' => true, 'enabled' => false]);

        $this->assertSame(0, SupportVisitorPresence::count());
    }

    public function test_beacon_upserts_same_row_and_keeps_first_seen(): void
    {
        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/a'])->assertOk();
        $created = SupportVisitorPresence::firstOrFail();
        $firstSeen = $created->first_seen_at;

        $this->travel(30)->seconds();

        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/b'])->assertOk();

        $this->assertSame(1, SupportVisitorPresence::count(), 'тот же guest_token → одна строка');
        $updated = SupportVisitorPresence::firstOrFail();
        $this->assertSame('https://samskrte.ru/b', $updated->current_url, 'текущая страница обновилась');
        $this->assertEquals(
            $firstSeen->timestamp,
            $updated->first_seen_at->timestamp,
            'first_seen_at не переписывается вторым beacon',
        );
        $this->assertTrue($updated->last_seen_at->gt($firstSeen), 'last_seen_at подвинулся');
    }

    public function test_geo_job_dispatched_only_on_create_and_only_with_geo_flag(): void
    {
        Queue::fake();
        config()->set('features.support_visitor_geo', true);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/support/presence', ['page' => 'https://samskrte.ru/a'])->assertOk();
        // второй beacon той же сессии — строка уже есть, гео повторно НЕ диспатчим
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/support/presence', ['page' => 'https://samskrte.ru/b'])->assertOk();

        Queue::assertPushed(ResolveVisitorPresenceGeoJob::class, 1);
    }

    public function test_geo_job_not_dispatched_when_geo_flag_off(): void
    {
        Queue::fake();
        config()->set('features.support_visitor_geo', false);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/support/presence', ['page' => 'https://samskrte.ru/a'])->assertOk();

        Queue::assertNotPushed(ResolveVisitorPresenceGeoJob::class);
    }

    public function test_geo_job_writes_city_to_presence_and_is_idempotent(): void
    {
        config()->set('support_geo.driver', 'cloudflare');

        $presence = SupportVisitorPresence::create([
            'guest_token' => 'tok-geo',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        (new ResolveVisitorPresenceGeoJob($presence->id, '203.0.113.7', [
            'city' => 'Москва', 'region' => 'Москва', 'country' => 'RU',
        ]))->handle(app(VisitorGeoResolver::class));

        $presence->refresh();
        $this->assertSame('Москва', $presence->visitor_city);
        $this->assertSame('Москва, RU', $presence->locationLabel());
        $this->assertNotNull($presence->visitor_geo_resolved_at);

        // Повторный прогон — no-op (гео уже разрешено).
        $resolvedAt = $presence->visitor_geo_resolved_at;
        (new ResolveVisitorPresenceGeoJob($presence->id, '203.0.113.7', [
            'city' => 'Питер', 'region' => 'СПб', 'country' => 'RU',
        ]))->handle(app(VisitorGeoResolver::class));
        $presence->refresh();
        $this->assertSame('Москва', $presence->visitor_city, 'идемпотентно: город не переписан');
        $this->assertEquals($resolvedAt->timestamp, $presence->visitor_geo_resolved_at->timestamp);
    }

    public function test_online_scope_returns_only_recently_seen(): void
    {
        config()->set('support_presence.online_window_seconds', 75);

        SupportVisitorPresence::create([
            'guest_token' => 'tok-live', 'first_seen_at' => now()->subMinutes(5), 'last_seen_at' => now()->subSeconds(20),
        ]);
        SupportVisitorPresence::create([
            'guest_token' => 'tok-gone', 'first_seen_at' => now()->subMinutes(10), 'last_seen_at' => now()->subMinutes(4),
        ]);

        $online = SupportVisitorPresence::query()->online()->pluck('guest_token')->all();
        $this->assertContains('tok-live', $online);
        $this->assertNotContains('tok-gone', $online);
    }

    public function test_prune_job_deletes_stale_and_keeps_fresh(): void
    {
        config()->set('support_presence.prune_after_minutes', 15);

        SupportVisitorPresence::create([
            'guest_token' => 'tok-fresh', 'first_seen_at' => now()->subMinutes(2), 'last_seen_at' => now()->subMinutes(1),
        ]);
        SupportVisitorPresence::create([
            'guest_token' => 'tok-stale', 'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(30),
        ]);

        (new PruneStaleVisitorPresencesJob)->handle();

        $this->assertDatabaseHas('support_visitor_presences', ['guest_token' => 'tok-fresh']);
        $this->assertDatabaseMissing('support_visitor_presences', ['guest_token' => 'tok-stale']);
    }

    public function test_beacon_returns_conversation_id_so_proactive_reaches_silent_visitor(): void
    {
        // 1) молчащий посетитель — тред ещё не существует.
        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/a'])
            ->assertOk()
            ->assertJson(['conversation_id' => null]);

        $guestToken = SupportVisitorPresence::firstOrFail()->guest_token;

        // 2) куратор пишет первым — создаётся гостевой тред по тому же guest_token.
        $thread = SupportConversation::create([
            'guest_token' => $guestToken,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
        ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'role' => 'curator',
            'text' => 'Здравствуйте! Подсказать по курсам?',
            'is_read' => true,
        ]);

        // 3) следующий beacon той же сессии возвращает id треда — виджет узнаёт и раскрывается.
        $this->postJson('/support/presence', ['page' => 'https://samskrte.ru/a'])
            ->assertOk()
            ->assertJson(['conversation_id' => $thread->id]);
    }
}
