<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MicShadowClassification;
use Illuminate\Support\Facades\Log;
use MessageClassifier\Loader as MicLoader;
use Throwable;

/**
 * H4608 — MIC shadow classify-all-inbound (G2 null telemetry + G8 near-miss).
 *
 * Каждое ВХОДЯЩЕЕ сообщение поддержки (telegram + web) прогоняется через
 * вендоренный MIC PHP-лоадер (tools/message-intent-classifier/php) и результат
 * пишется ТОЛЬКО в таблицу mic_shadow_classifications: одна строка на
 * (сообщение × плоскость) с {category, reason, null} и near-miss top-2
 * (почти сработавшие правила с причиной). Никаких ответов, никаких изменений
 * поведения: метод record() никогда не бросает и никогда не пишет текст
 * сообщения — только sha256 нормализованного текста (PII-фенс H2641/H3502,
 * сырой текст живёт лишь в маскированных корпусах MIC).
 *
 * Гейт: config('features.mic_shadow_classify') (default OFF). OFF = ровно
 * ноль вызовов MIC. Рантайм-флип классификатора остаётся за H3529
 * (precision >=93% на корпусе) — этот флаг его не включает.
 *
 * Near-miss (G8, семантика comparative-doc §3.8): скан правил плоскости в
 * порядке приоритета; кандидат near-miss — правило, у которого сматчился
 * pattern, но (а) negation заблокировал → reason 'negation:<negation>' или
 * (б) раньше выиграло другое правило → 'runner-up:<pattern>'. Берутся первые
 * два таких в порядке правил.
 */
final class MicShadowClassifier
{
    private const CHANNELS = ['telegram', 'web'];

    private const NEAR_MISS_LIMIT = 2;

    private static ?self $instance = null;

    private function __construct(private readonly MicLoader $loader) {}

    /**
     * Ленивая загрузка вендоренного лоадера. null = пакет отсутствует
     * (например, обрезанный деплой) — shadow-режим тогда просто молчит.
     */
    public static function instance(): ?self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $root = base_path('tools/message-intent-classifier');
        if (! is_file($root.'/rules/v1/topic.yaml') || ! is_file($root.'/php/MessageClassifier/Loader.php')) {
            return null;
        }

        if (! class_exists(MicLoader::class, false)) {
            require_once $root.'/php/MessageClassifier/Loader.php';
            require_once $root.'/php/MessageClassifier/Classifier.php';
        }

        try {
            return self::$instance = new self(new MicLoader($root));
        } catch (Throwable $e) {
            Log::warning('mic_shadow_classify_loader_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Log-only запись классификации одного входящего. Никогда не бросает:
     * сбой телеметрии не имеет права ломать путь ответа.
     */
    public function record(
        string $channel,
        ?int $conversationId,
        ?int $messageId,
        string $text,
    ): void {
        if (! in_array($channel, self::CHANNELS, true)) {
            return;
        }

        if (! (bool) config('features.mic_shadow_classify')) {
            return; // flag OFF: ноль вызовов, ноль записей, ноль поведения.
        }

        try {
            $normalized = MicLoader::normalizeText($text);
            $hash = hash('sha256', $normalized);
            $now = now();

            foreach (MicLoader::PLANES as $plane) {
                [$winner, $nearMiss] = $this->classifyPlaneWithNearMiss($plane, $normalized);

                MicShadowClassification::query()->updateOrCreate(
                    [
                        'channel' => $channel,
                        'message_id' => $messageId,
                        'plane' => $plane,
                    ],
                    [
                        'conversation_id' => $conversationId,
                        'text_hash' => $hash,
                        'category' => $winner['category'] ?? null,
                        'reason' => $winner['reason'] ?? null,
                        'near_miss' => $nearMiss === [] ? null : $nearMiss,
                        'classified_at' => $now,
                    ],
                );
            }
        } catch (Throwable $e) {
            // Никакого текста/хеша в лог — только канал и причина сбоя.
            Log::warning('mic_shadow_classify_record_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Классификация одной плоскости + near-miss top-2.
     *
     * @return array{0: array{category: string, reason: string}|null, 1: list<array{category: string, reason: string}>}
     */
    public function classifyPlaneWithNearMiss(string $plane, string $normalizedText): array
    {
        $winner = null;
        $nearMiss = [];

        foreach ($this->loader->rulesFor($plane) as $rule) {
            if (! $rule['enabled']) {
                continue;
            }

            $blockedBy = null;
            foreach ($rule['negations'] as $negation) {
                if (self::match((string) $negation, $normalizedText)) {
                    $blockedBy = (string) $negation;
                    break;
                }
            }

            $matchedPattern = null;
            if ($blockedBy === null) {
                foreach ($rule['patterns'] as $pattern) {
                    if (self::match((string) $pattern, $normalizedText)) {
                        $matchedPattern = (string) $pattern;
                        break;
                    }
                }
            }

            if ($matchedPattern !== null) {
                if ($winner === null) {
                    $winner = [
                        'category' => (string) $rule['category'],
                        'reason' => 'keyword:'.$matchedPattern,
                    ];
                } elseif (count($nearMiss) < self::NEAR_MISS_LIMIT) {
                    $nearMiss[] = [
                        'category' => (string) $rule['category'],
                        'reason' => 'runner-up:'.$matchedPattern,
                    ];
                }
            } elseif ($blockedBy !== null && count($nearMiss) < self::NEAR_MISS_LIMIT) {
                $nearMiss[] = [
                    'category' => (string) $rule['category'],
                    'reason' => 'negation:'.$blockedBy,
                ];
            }

            if (count($nearMiss) >= self::NEAR_MISS_LIMIT) {
                break;
            }
        }

        return [$winner, $nearMiss];
    }

    /** Та же семантика матча, что у вендоренного Classifier (PCRE '~pattern~u'). */
    private static function match(string $pattern, string $subject): bool
    {
        return preg_match('~'.$pattern.'~u', $subject) === 1;
    }
}
