<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Filament\Pages\TelegramSupportAnalytics;
use App\Models\MarketingSetting;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Вчерашняя сводка поддержки для Telegram (H3242). Числа — тот же пакет, что
 * Filament-страница; 🍎/gasuns — маркер исходящих H3233. Без имён студентов.
 *
 * H4429 (рулинг MG 08-09-2026): блок «LLM-тень» — вчерашние сформулированные
 * черновики ветки H4404, пока SUPPORT_DM_LLM_DRAFTS_LIVE выключен. Черновик —
 * продукт ветки, его текст не приватнее самого ответа, который студент и так
 * получил бы; имя студента и идентификаторы не выводятся.
 */
final class SupportDailyDigest
{
    public const ANALYTICS_PATH = '/admin/telegram-support/telegram-support-analytics';

    /** Максимальная длина черновика-примера в сводке (символы). */
    public const DRAFT_EXCERPT_LIMIT = 200;

    /** H4429: сколько дней тени подряд нужно до живого включения (рулинг MG: неделя, без переспрашивания). */
    public const STREAK_MAX_DAYS = 7;

    public function __construct(
        private readonly SupportDashboardPacketBuilder $packetBuilder,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     metrics: array<string, mixed>,
     *     topics: list<array{category: string, total: int}>,
     *     attribution: array{apple: int, gasuns: int, ai: int, other: int},
     *     url: string,
     *     text: string
     * }
     */
    public function snapshot(?string $date = null): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $day = $date
            ? CarbonImmutable::parse($date, $tz)->startOfDay()
            : CarbonImmutable::now($tz)->subDay()->startOfDay();

        $packet = $this->packetBuilder->build($day->toDateString());
        $metrics = $packet['summary']['today'];
        $topics = $packet['topics'];
        $attribution = $this->attributionForDate($day);
        $url = $this->analyticsUrl();
        $llm = $this->llmShadowForDate($day);

        return [
            'date' => $day->toDateString(),
            'metrics' => $metrics,
            'topics' => $topics,
            'attribution' => $attribution,
            'url' => $url,
            'text' => $this->formatHtml($day, $metrics, $topics, $attribution, $url, $llm),
        ];
    }

    /**
     * Блок H4429: вчерашние события LLM-ветки (тень/отказы/отправки) + пример
     * черновика + оценка расхода. null — события LLM-ветки за день есть, но
     * ветка полностью выключена (флаг OFF → раздел в сводку не тянем).
     *
     * @return array{
     *     relevant: bool,
     *     would_send: int,
     *     refused: array<string, int>,
     *     sent: int,
     *     live: bool,
     *     draft_excerpt: ?string,
     *     model: ?string,
     *     spend_usd: ?float,
     *     streak_days: int,
     *     live_enabled_at: ?string
     * }|null
     */
    public function llmShadowForDate(CarbonImmutable $day): ?array
    {
        if (! (bool) config('features.support_dm_llm_drafts', false)) {
            return null;
        }

        $events = SupportAiReplyEvent::query()
            ->whereBetween('created_at', [$day->startOfDay(), $day->endOfDay()])
            ->whereIn('event_type', [
                SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND,
                SupportDmAutoReply::EVENT_LLM_REFUSED,
                SupportDmAutoReply::EVENT_SENT,
            ])
            ->orderBy('id')
            ->get(['event_type', 'meta']);

        $wouldSend = 0;
        $refused = [];
        $sent = 0;
        $excerpt = null;
        $model = null;
        $spend = 0.0;
        $priced = false;

        $pricing = (array) config('services.openrouter.pricing', []);

        foreach ($events as $event) {
            $meta = (array) ($event->meta ?? []);

            match ($event->event_type) {
                SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND => $wouldSend++,
                SupportDmAutoReply::EVENT_LLM_REFUSED => self::bump($refused, (string) ($meta['reason'] ?? 'unknown')),
                SupportDmAutoReply::EVENT_SENT => self::bump($sent, 0),
                default => null,
            };

            if ($event->event_type === SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND) {
                $model ??= is_string($meta['model'] ?? null) ? $meta['model'] : null;
                $excerpt ??= is_string($meta['draft'] ?? null) ? mb_substr($meta['draft'], 0, self::DRAFT_EXCERPT_LIMIT) : null;
            }

            if ($event->event_type === SupportDmAutoReply::EVENT_SENT
                && ($meta['kind'] ?? null) === SupportDmAutoReply::KIND_LLM_DRAFT) {
                $model ??= is_string($meta['model'] ?? null) ? $meta['model'] : null;
            }

            $usage = $meta['usage'] ?? null;
            $rate = $model !== null ? ($pricing[$model] ?? null) : null;
            if (is_array($usage) && is_array($rate)) {
                $spend += ((int) ($usage['prompt_tokens'] ?? 0) / 1_000_000) * (float) $rate['prompt_per_1m']
                    + ((int) ($usage['completion_tokens'] ?? 0) / 1_000_000) * (float) $rate['completion_per_1m'];
                $priced = true;
            }
        }

        if ($wouldSend === 0 && $sent === 0 && $refused === []) {
            return [
                'relevant' => false,
                'would_send' => 0,
                'refused' => [],
                'sent' => 0,
                'live' => false,
                'draft_excerpt' => null,
                'model' => null,
                'spend_usd' => null,
                'streak_days' => $this->liveStreakDays($day),
                'live_enabled_at' => $this->liveEnabledAt(),
            ];
        }

        return [
            'relevant' => true,
            'would_send' => $wouldSend,
            'refused' => $refused,
            'sent' => $sent,
            'live' => self::llmLive(),
            'draft_excerpt' => $excerpt,
            'model' => $model,
            'spend_usd' => $priced ? round($spend, 4) : null,
            'streak_days' => $this->liveStreakDays($day),
            'live_enabled_at' => $this->liveEnabledAt(),
        ];
    }

    /**
     * Текущее состояние живого режима LLM-ветки: env-флаг ИЛИ включение
     * авто-рубильником H4429 (строка в marketing_settings).
     */
    public static function llmLive(): bool
    {
        if ((bool) config('features.support_dm_llm_drafts_live', false)) {
            return true;
        }

        return (bool) MarketingSetting::cached()?->support_llm_live_enabled_at;
    }

    /**
     * Сколько ПОСЛЕДНИХ дней подряд (включая $day) содержали хоть одно
     * сформулированное событие тени или живой отправки LLM-ветки. Растёт,
     * пока день за днём есть активность; 0 — вчера пусто.
     */
    private function liveStreakDays(CarbonImmutable $day): int
    {
        $min = $day->subDays(self::STREAK_MAX_DAYS);
        $dates = SupportAiReplyEvent::query()
            ->whereBetween('created_at', [$min->startOfDay(), $day->endOfDay()])
            ->whereIn('event_type', [
                SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND,
                SupportDmAutoReply::EVENT_SENT,
            ])
            ->get(['event_type', 'created_at'])
            ->groupBy(fn ($event) => $event->created_at->copy()->setTimezone((string) config('app.timezone', 'Europe/Moscow'))->toDateString())
            ->keys()
            ->all();

        $streak = 0;
        $cursor = $day;
        while ($cursor->gte($min) && in_array($cursor->toDateString(), $dates, true)) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    private function liveEnabledAt(): ?string
    {
        $at = MarketingSetting::cached()?->support_llm_live_enabled_at;

        return $at === null ? null : $at->setTimezone((string) config('app.timezone', 'Europe/Moscow'))->format('d.m.Y H:i');
    }

    /**
     * @param  array<string, int>  $bucket
     */
    private static function bump(array &$bucket, int|string $key): void
    {
        if (is_int($key)) {
            $bucket['sent'] = ($bucket['sent'] ?? 0) + 1;

            return;
        }

        $bucket[$key] = ($bucket[$key] ?? 0) + 1;
    }

    /**
     * @return array{apple: int, gasuns: int, ai: int, other: int}
     */
    private function attributionForDate(CarbonImmutable $day): array
    {
        $counts = ['apple' => 0, 'gasuns' => 0, 'ai' => 0, 'other' => 0];

        $messages = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->whereBetween('sent_at', [$day->startOfDay(), $day->endOfDay()])
            ->get(['responder_type', 'responder_marker', 'ai_state']);

        foreach ($messages as $message) {
            if ($message->responder_type === 'ai' || $message->ai_state === 'sent') {
                $counts['ai']++;

                continue;
            }

            $marker = (string) ($message->responder_marker ?: SupportOutgoingAttribution::GASUNS_MARKER);
            if ($marker === SupportOutgoingAttribution::APPLE_MARKER) {
                $counts['apple']++;
            } elseif ($marker === SupportOutgoingAttribution::GASUNS_MARKER) {
                $counts['gasuns']++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<array{category: string, total: int}>  $topics
     * @param  array{apple: int, gasuns: int, ai: int, other: int}  $attribution
     * @param  array{relevant: bool, would_send: int, refused: array<string, int>, sent: int, live: bool, draft_excerpt: ?string, model: ?string, spend_usd: ?float, streak_days: int, live_enabled_at: ?string}|null  $llm
     */
    private function formatHtml(
        CarbonImmutable $day,
        array $metrics,
        array $topics,
        array $attribution,
        string $url,
        ?array $llm = null,
    ): string {
        $topicLine = 'нет';
        if ($topics !== []) {
            $topicLine = implode(', ', array_map(
                fn (array $row) => e((string) $row['category']).' '.(int) $row['total'],
                array_slice($topics, 0, 8),
            ));
        }

        $lines = [
            '<b>Сводка поддержки за '.$day->format('d.m.Y').'</b>',
            '',
            'Обращений: '.(int) ($metrics['conversations'] ?? 0),
            'Входящих: '.(int) ($metrics['incoming'] ?? 0),
            'Исходящих: '.(int) ($metrics['outgoing'] ?? 0),
            'Неотвеченных: '.(int) ($metrics['unanswered'] ?? 0),
            'Новых контактов: '.(int) ($metrics['new_contacts'] ?? 0),
            'ИИ отправил: '.(int) ($metrics['ai_sent'] ?? 0),
            'Горбаченко '.SupportOutgoingAttribution::APPLE_MARKER.': '.$attribution['apple'],
            'Гасунс: '.$attribution['gasuns'],
        ];

        if ($attribution['other'] > 0) {
            $lines[] = 'Прочие исходящие: '.$attribution['other'];
        }

        $lines[] = 'Темы: '.$topicLine;

        if ($llm !== null) {
            foreach ($this->llmLines($llm) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = '<a href="'.e($url).'">Аналитика</a>';

        return implode("\n", $lines);
    }

    /**
     * Строки блока «LLM-тень» (H4429). Пустой список — дня без событий.
     *
     * @param  array{relevant: bool, would_send: int, refused: array<string, int>, sent: int, live: bool, draft_excerpt: ?string, model: ?string, spend_usd: ?float, streak_days: int, live_enabled_at: ?string}  $llm
     * @return list<string>
     */
    private function llmLines(array $llm): array
    {
        if (! $llm['relevant']) {
            return $llm['live_enabled_at'] !== null
                ? ['', 'LLM-ветка: живой режим (вкл. '.$llm['live_enabled_at'].')']
                : [];
        }

        $mode = $llm['live'] ? 'ЖИВОЙ' : 'тень';
        $lines = ['', '<b>LLM-ветка ('.$mode.')</b>'];

        if ($llm['live'] && $llm['sent'] > 0) {
            $lines[] = 'Отправлено: '.$llm['sent'];
        } elseif (! $llm['live']) {
            $lines[] = 'Сформулировано (тень): '.$llm['would_send'];
        }

        if ($llm['refused'] !== []) {
            $refused = array_map(
                fn (string $reason, int $count) => $reason.' '.$count,
                array_keys($llm['refused']),
                array_values($llm['refused']),
            );
            $lines[] = 'Отказы R3: '.implode(', ', $refused);
        }

        if ($llm['draft_excerpt'] !== null && $llm['model'] !== null) {
            $lines[] = 'Пример ('.$llm['model'].'): '.e($llm['draft_excerpt']);
        }

        if ($llm['spend_usd'] !== null) {
            $lines[] = 'Расход за день: $'.number_format($llm['spend_usd'], 4, '.', ' ');
        }

        if (! $llm['live'] && $llm['streak_days'] > 0) {
            $left = self::STREAK_MAX_DAYS - $llm['streak_days'];
            $lines[] = $left > 0
                ? 'Дней тени подряд: '.$llm['streak_days'].' из '.self::STREAK_MAX_DAYS.' (до живого включения: '.$left.')'
                : 'Дней тени подряд: '.$llm['streak_days'].' — порог достигнут, живой режим включится ближайшим прогоном support:llm-live-enable';
        }

        if ($llm['live_enabled_at'] !== null) {
            $lines[] = 'Живой режим вкл.: '.$llm['live_enabled_at'];
        }

        return $lines;
    }

    public function analyticsUrl(): string
    {
        try {
            return TelegramSupportAnalytics::getUrl();
        } catch (Throwable) {
            return rtrim((string) config('app.url'), '/').self::ANALYTICS_PATH;
        }
    }
}
