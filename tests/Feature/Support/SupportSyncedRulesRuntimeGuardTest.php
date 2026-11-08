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
 * H3529 (помеченный дефолт) + H7803 (флип за флагом): синхронизированные из
 * пакета правила (pattern_hash NOT NULL) несут PCRE-паттерны, а не плоские
 * ключевые слова.
 *
 * - флаг `features.support_topic_synced_rules` OFF (default): synced-строки
 *   рантайму не видны, легаси-ключевые слова работают как раньше;
 * - флаг ON: PCRE-матчинг в семантике reference-движка (нормализация,
 *   priority asc, negations, первый матч), легаси — фолбэк.
 */
class SupportSyncedRulesRuntimeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('features.support_topic_synced_rules'));
    }

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
            'text' => 'Как пройти оплату? Сайт не работает.',
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

    public function test_flag_on_matches_synced_regex_rules(): void
    {
        config(['features.support_topic_synced_rules' => true]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('d', 64),
            'keywords' => ['техподдержк[а-я]*', 'не работает'],
            'negations' => [],
            'priority' => 35,
            'is_enabled' => true,
        ]);

        $rollup = $this->rollupForText('Техподдержка не отвечает, занятие не работает.');

        app(SupportTopicClassifier::class)->classify($rollup);

        $assignment = $rollup->topicAssignments()->firstOrFail();

        $this->assertSame('tech_issue', $assignment->category);
        $this->assertSame(SupportTopicClassifier::SOURCE_SYNCED, $assignment->source);
        $this->assertStringStartsWith('regex:', (string) $assignment->reason);
    }

    public function test_flag_on_respects_negations_and_first_match_wins(): void
    {
        config(['features.support_topic_synced_rules' => true]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('e', 64),
            'keywords' => ['не работает'],
            'negations' => [],
            'priority' => 35,
            'is_enabled' => true,
        ]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'access_login',
            'pattern_hash' => str_repeat('f', 64),
            'keywords' => ['кабинет'],
            'negations' => ['техподдержк'],
            'priority' => 70,
            'is_enabled' => true,
        ]);

        $first = $this->rollupForText('Кабинет не работает, пишет ошибка.');

        app(SupportTopicClassifier::class)->classify($first);

        $this->assertSame(
            ['tech_issue'],
            $first->topicAssignments()->pluck('category')->all(),
            'priority 35 wins; synced match is exclusive (reference-engine semantics)',
        );

        $negated = $this->rollupForText('Техподдержка: не могу войти в кабинет.');

        app(SupportTopicClassifier::class)->classify($negated);

        $this->assertSame(
            ['uncategorized'],
            $negated->topicAssignments()->pluck('category')->all(),
            'negation «техподдержк» blocks access_login instead of inventing a topic',
        );
    }

    public function test_flag_on_falls_back_to_legacy_keywords_when_no_synced_rule_matches(): void
    {
        config(['features.support_topic_synced_rules' => true]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('a', 64),
            'keywords' => ['не работает'],
            'negations' => [],
            'priority' => 35,
            'is_enabled' => true,
        ]);

        SupportTopicRule::create([
            'category' => 'payment_billing',
            'keywords' => ['оплат'],
            'priority' => 30,
            'is_enabled' => true,
        ]);

        $rollup = $this->rollupForText('Как оплатить следующий блок?');

        app(SupportTopicClassifier::class)->classify($rollup);

        $assignment = $rollup->topicAssignments()->firstOrFail();

        $this->assertSame('payment_billing', $assignment->category);
        $this->assertSame('keyword', $assignment->source);
    }

    public function test_flag_on_skips_broken_regex_instead_of_failing(): void
    {
        config(['features.support_topic_synced_rules' => true]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('b', 64),
            'keywords' => ['(незакрытая[скобка'],
            'negations' => [],
            'priority' => 1,
            'is_enabled' => true,
        ]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'schedule',
            'pattern_hash' => str_repeat('1', 64),
            'keywords' => ['расписан[а-я]*'],
            'negations' => [],
            'priority' => 20,
            'is_enabled' => true,
        ]);

        $rollup = $this->rollupForText('Пришлите расписание занятий.');

        app(SupportTopicClassifier::class)->classify($rollup);

        $this->assertSame('schedule', $rollup->topicAssignments()->firstOrFail()->category);
    }

    public function test_flag_on_reclassify_is_idempotent(): void
    {
        config(['features.support_topic_synced_rules' => true]);

        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'tech_issue',
            'pattern_hash' => str_repeat('9', 64),
            'keywords' => ['не работает'],
            'negations' => [],
            'priority' => 35,
            'is_enabled' => true,
        ]);

        $rollup = $this->rollupForText('Ничего не работает.');

        app(SupportTopicClassifier::class)->classify($rollup);
        app(SupportTopicClassifier::class)->classify($rollup);

        $this->assertSame(1, $rollup->topicAssignments()->count());
    }

    private function rollupForText(string $text): SupportDailyRollup
    {
        $student = User::factory()->create();

        $message = ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => $text,
        ]);
        $message->forceFill(['created_at' => '2026-11-06 09:00:00'])->save();

        return SupportDailyRollup::create([
            'channel' => SupportDailyRollup::CHANNEL_WEB,
            'web_user_id' => $student->id,
            'conversation_date' => '2026-11-06 00:00:00',
            'incoming_count' => 1,
        ]);
    }
}
