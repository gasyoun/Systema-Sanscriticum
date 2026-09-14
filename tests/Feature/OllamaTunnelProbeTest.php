<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerGuards\OllamaTunnelProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4845 — живость reverse-туннеля к Ollama (127.0.0.1:11434).
 *
 * Гейты проверки: туннель нужен (флаг-потребитель включён) И сейчас рабочие
 * часы GPU-узла. Отказ соединения / таймаут / не-2xx — именованная находка.
 */
class OllamaTunnelProbeTest extends TestCase
{
    private const TAGS = 'http://127.0.0.1:11434/api/tags';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'knowledge.driver' => 'ollama',
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.tunnel_hours' => '09:30-20:30',
            'features.faq_hybrid_retrieval' => true,
            'features.bot_ollama_shadow' => false,
            'features.bot_local_generation' => false,
            'cabinet_probe.ollama_tunnel_attempts' => 2,
            'cabinet_probe.ollama_tunnel_pause_seconds' => 0,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_tunnel_is_no_finding(): void
    {
        Http::fake([self::TAGS => Http::response(['models' => [['name' => 'bge-m3:latest']]], 200)]);

        $this->assertNull((new OllamaTunnelProbe)->failure());
        Http::assertSentCount(1);
    }

    public function test_dead_tunnel_is_a_named_finding_after_retry(): void
    {
        Http::fake([self::TAGS => Http::failedConnection(
            'cURL error 7: Failed to connect to 127.0.0.1 port 11434 after 0 ms: Could not connect to server',
        )]);

        $failure = (new OllamaTunnelProbe)->failure();

        $this->assertNotNull($failure);
        $this->assertStringStartsWith('ollama-tunnel: http://127.0.0.1:11434 недоступен', $failure);
        $this->assertStringContainsString('нет слушателя на порту (reverse-туннель мёртв, cURL 7)', $failure);
        $this->assertStringContainsString('dense-нога FAQ-поиска', $failure);
        $this->assertStringContainsString('docs/ops/OLLAMA_TUNNEL_RUNBOOK.md', $failure);
        // Перепроба перед тревогой: флап туннеля не должен будить TG.
        Http::assertSentCount(2);
    }

    public function test_retry_success_counts_as_alive(): void
    {
        Http::fake([self::TAGS => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out after 5001 milliseconds')
            ->push(['models' => []], 200)]);

        $this->assertNull((new OllamaTunnelProbe)->failure());
    }

    public function test_timeout_is_named_as_a_hung_tunnel(): void
    {
        Http::fake([self::TAGS => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')]);

        $this->assertStringContainsString('таймаут (туннель висит или узел не отвечает, cURL 28)', (string) (new OllamaTunnelProbe)->failure());
    }

    public function test_non_2xx_means_tunnel_up_but_no_ollama_behind_it(): void
    {
        Http::fake([self::TAGS => Http::response('bad gateway', 502)]);

        $this->assertStringContainsString('узел ответил HTTP 502', (string) (new OllamaTunnelProbe)->failure());
    }

    public function test_silent_when_no_consumer_needs_the_tunnel(): void
    {
        // Гибрид без драйвера ollama туннель не зовёт (EmbeddingProvider = Null).
        config(['knowledge.driver' => '', 'features.faq_hybrid_retrieval' => true]);
        Http::fake();

        $this->assertSame([], OllamaTunnelProbe::dependents());
        $this->assertNull((new OllamaTunnelProbe)->failure());
        Http::assertNothingSent();
    }

    public function test_shadow_alone_makes_the_tunnel_required(): void
    {
        config(['knowledge.driver' => '', 'features.bot_ollama_shadow' => true]);

        $this->assertSame(['теневая генерация BOT_OLLAMA_SHADOW'], OllamaTunnelProbe::dependents());
    }

    public function test_node_asleep_outside_service_window_is_not_an_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 01:45:00', 'Europe/Moscow'));
        Http::fake();

        $this->assertNull((new OllamaTunnelProbe)->failure());
        Http::assertNothingSent();
    }

    public function test_service_window_edges_and_overnight_and_fallback(): void
    {
        $at = fn (string $time) => Carbon::parse('2026-09-14 '.$time, 'Europe/Moscow');

        $this->assertFalse(OllamaTunnelProbe::withinServiceWindow($at('09:29')));
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('09:30')));
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('20:29')));
        $this->assertFalse(OllamaTunnelProbe::withinServiceWindow($at('20:30')));

        config(['knowledge.tunnel_hours' => '22:00-06:00']);
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('23:00')));
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('05:59')));
        $this->assertFalse(OllamaTunnelProbe::withinServiceWindow($at('12:00')));

        // Пусто или опечатка — круглосуточно: лучше лишняя тревога, чем вечное молчание.
        config(['knowledge.tunnel_hours' => '']);
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('03:00')));
        config(['knowledge.tunnel_hours' => '9-21']);
        $this->assertTrue(OllamaTunnelProbe::withinServiceWindow($at('03:00')));
    }
}
