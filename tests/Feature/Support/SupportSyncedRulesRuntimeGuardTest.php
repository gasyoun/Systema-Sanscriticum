<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicRule;
use App\Models\User;
use App\Services\TelegramSupport\SupportTopicClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3529 (помеченный дефолт): синхронизированные из пакета правила
 * (pattern_hash NOT NULL) хранятся в БД, но рантайм-классификатор их пока НЕ
 * видит — плоский mb_stripos не понимает YAML-паттерны, а среди них есть
 * литералы («техподдержк», «не работает»…), которые изменили бы живую
 * классификацию до гейта precision ≥93%. Легаси-правила работают как раньше.
 */
class SupportSyncedRulesRuntimeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifier_ignores_synced_rules_but_keeps_legacy_ones(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        // Легаси-правило (pattern_hash NULL) — должно матчить как раньше.
        $legacy = SupportTopicRule::create([
            'category' => 'payment_billing',
            'keywords' => ['оплат'],
            'priority' => 30,
            'is_enabled' => true,
        ]);
        $this->assertNull($legacy->pattern_hash);

        // Синхронизированное правило с ЛИТЕРАЛЬНЫМ ключом, которое без гарда
        // задвоило бы тему: тот же текст содержит и «техподдержк»-паттерн.
        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('c', 64),
            'keywords' => ['не работает'],
            'negations' => [],
            'priority' => 35,
            'is_enabled' => true,
        ]);

        $student = User::factory()->create();

        $message = ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Как пройти оплату? Ссылка не приходит.',
        ]);
        $message->forceFill(['created_at' => '2026-08-25 09:00:00'])->save();

        $rollup = SupportDailyRollup::create([
            'channel' => SupportDailyRollup::CHANNEL_WEB,
            'web_user_id' => $student->id,
            'conversation_date' => '2026-08-25 00:00:00',
            'incoming_count' => 1,
        ]);

        app(SupportTopicClassifier::class)->classify($rollup);

        $categories = $rollup->topicAssignments()->pluck('category')->all();

        $this->assertContains('payment_billing', $categories, 'legacy keyword rule must keep working');
        $this->assertNotContains('tech_issue', $categories, 'synced (pattern_hash) rules must stay invisible to runtime until the regex-engine gate');
    }
}
