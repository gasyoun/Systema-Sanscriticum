<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H6177 (E026, hermes-24-01a10bca): учёт черновиков и человеческих минут.
 *
 * Инструмент «минутного журнала» программы H6120 (SCHOOL_AGENTS_WEEKLY_METRICS
 * 2026-10-05: «Заполняется человеком или агентом по факту; каждая запись
 * датирована») поверх СУЩЕСТВУЮЩЕГО механизма: журнал
 * support_ai_reply_events ({@see SupportAiReplyEvent}) и словарь источников
 * очереди черновиков ({@see SupportAnswerSuggestion::SOURCE_*}) — без
 * параллельного механизма записи.
 *
 * Контракт миссии:
 *  1. исходы разбора черновика разделены: accepted/minor (принят как есть или
 *     с мелкой правкой), substantial-edit (существенная правка), reject
 *     (отклонён — остаётся в знаменателе, «выборка включает отказы»);
 *  2. отправка НЕ выводится из принятия: sent — независимое измерение
 *     (принятый черновик может не уйти; отправка может случиться без
 *     «принятия»), sent_n считается только по флагу sent;
 *  3. учтены три категории человеческих минут: review (проверка черновиков),
 *     correction (исправления), incident (инциденты);
 *  4. отрицательное время запрещено — запись с любым отрицательным или
 *     нечисловым временем отклоняется целиком, ничего не пишется;
 *  5. события дедуплицируются: повтор того же event_key не пишет вторую
 *     строку и не растит счётчики;
 *  6. сохраняемые строки — только дескрипторы и счётчики, без текста
 *     учеников и без реальных сообщений.
 *
 * Никаких production-включений, отправок и платных вызовов: журнал только
 * дополняется записями учёта, агрегат — одна строка на окно (повторное
 * сохранение обновляет ту же строку). Сводные недельные метрики и 90-дневный
 * отчёт — за H6120; этот инструмент даёт только per-unit измерение.
 */
final class DraftReviewMinutesLedger
{
    /** Принят как есть или с мелкой правкой (числитель «Принято черновиков»). */
    public const OUTCOME_ACCEPTED_MINOR = 'accepted_minor';

    /** Существенная правка — в знаменателе, не в числителе доли. */
    public const OUTCOME_SUBSTANTIAL_EDIT = 'substantial_edit';

    /** Отклонён — в знаменателе («выборка включает отказы»). */
    public const OUTCOME_REJECT = 'reject';

    /** @var list<string> */
    public const OUTCOMES = [
        self::OUTCOME_ACCEPTED_MINOR,
        self::OUTCOME_SUBSTANTIAL_EDIT,
        self::OUTCOME_REJECT,
    ];

    /** Минуты проверки черновиков. */
    public const MINUTE_KIND_REVIEW = 'review';

    /** Минуты исправлений. */
    public const MINUTE_KIND_CORRECTION = 'correction';

    /** Минуты инцидентов. */
    public const MINUTE_KIND_INCIDENT = 'incident';

    /** @var list<string> */
    public const MINUTE_KINDS = [
        self::MINUTE_KIND_REVIEW,
        self::MINUTE_KIND_CORRECTION,
        self::MINUTE_KIND_INCIDENT,
    ];

    /** Ключи meta записи журнала — ровно дескриптор события, без текста. */
    public const EVENT_META_KEYS = [
        'event_key',
        'draft_key',
        'outcome',
        'sent',
        'review_minutes',
        'correction_minutes',
        'incident_minutes',
        'occurred_at',
    ];

    /** Ключи сохраняемого агрегата — ровно счётчики и ключ окна, без текста. */
    public const AGGREGATE_KEYS = [
        'window_from',
        'window_to',
        'accepted_minor_n',
        'substantial_edit_n',
        'reject_n',
        'total_reviews',
        'sent_n',
        'review_minutes_total',
        'correction_minutes_total',
        'incident_minutes_total',
        'minutes_total',
        'duplicates_ignored',
        'total_distinct',
    ];

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_INVALID_OUTCOME = 'invalid_outcome';

    /** Отрицательное время запрещено — запись отклоняется целиком. */
    public const STATUS_REJECTED_NEGATIVE_TIME = 'rejected_negative_time';

    /** Время должно быть числом — нечисловое значение отклоняется целиком. */
    public const STATUS_INVALID_MINUTES = 'invalid_minutes';

    /**
     * Ключ черновика в терминах существующих источников очереди черновиков
     * ({@see SupportAnswerSuggestion::SOURCE_*}) — например
     * `chat_message:42` / `telegram_support_message:123`. Без текста.
     */
    public static function draftKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId;
    }

    /**
     * Записать одно событие журнала. Валидация: известный вердикт, время
     * неотрицательное число, дедупликация по event_key. Невалидная запись не
     * пишется вовсе (атомарная отмена всей записи, не обрезка поля).
     *
     * @param  array{event_key: string, draft_key: string, outcome: string, sent?: bool, review_minutes?: int|float, correction_minutes?: int|float, incident_minutes?: int|float, occurred_at: CarbonInterface}  $event
     * @return array{status: string, event: ?SupportAiReplyEvent}
     */
    public function recordEvent(array $event): array
    {
        $outcome = (string) ($event['outcome'] ?? '');

        if (! in_array($outcome, self::OUTCOMES, true)) {
            return ['status' => self::STATUS_INVALID_OUTCOME, 'event' => null];
        }

        $minutes = [];
        foreach (self::MINUTE_KINDS as $kind) {
            $value = $event[$kind.'_minutes'] ?? 0;

            if (! is_numeric($value)) {
                return ['status' => self::STATUS_INVALID_MINUTES, 'event' => null];
            }

            if ((float) $value < 0) {
                return ['status' => self::STATUS_REJECTED_NEGATIVE_TIME, 'event' => null];
            }

            $minutes[$kind] = (float) $value;
        }

        $eventKey = (string) ($event['event_key'] ?? '');

        $duplicate = SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_MINUTES)
            ->where('meta->event_key', $eventKey)
            ->first();

        if ($duplicate !== null) {
            // Повтор события — вторая строка не создаётся, счётчики не растут.
            return ['status' => self::STATUS_DUPLICATE, 'event' => $duplicate];
        }

        $recorded = SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAiReplyEvent::EVENT_DRAFT_REVIEW_MINUTES,
            'meta' => [
                'event_key' => $eventKey,
                'draft_key' => (string) ($event['draft_key'] ?? ''),
                'outcome' => $outcome,
                'sent' => (bool) ($event['sent'] ?? false),
                'review_minutes' => $minutes[self::MINUTE_KIND_REVIEW],
                'correction_minutes' => $minutes[self::MINUTE_KIND_CORRECTION],
                'incident_minutes' => $minutes[self::MINUTE_KIND_INCIDENT],
                'occurred_at' => $event['occurred_at']->toIso8601String(),
            ],
        ]);

        return ['status' => self::STATUS_RECORDED, 'event' => $recorded];
    }

    /**
     * События журнала окна: дескрипторы БЕЗ текста (ключи, вердикт, флаг
     * отправки, минуты, время).
     *
     * @return Collection<int, array{event_key: string, draft_key: string, outcome: string, sent: bool, review_minutes: float, correction_minutes: float, incident_minutes: float, occurred_at: CarbonInterface}>
     */
    public function eventsForWindow(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_MINUTES)
            ->orderBy('id')
            ->get()
            ->filter(function (SupportAiReplyEvent $row) use ($from, $to): bool {
                $occurredAt = Carbon::parse((string) ($row->meta['occurred_at'] ?? ''));

                return $occurredAt->gte($from) && $occurredAt->lte($to);
            })
            ->map(fn (SupportAiReplyEvent $row): array => [
                'event_key' => (string) ($row->meta['event_key'] ?? ''),
                'draft_key' => (string) ($row->meta['draft_key'] ?? ''),
                'outcome' => (string) ($row->meta['outcome'] ?? ''),
                'sent' => (bool) ($row->meta['sent'] ?? false),
                'review_minutes' => (float) ($row->meta['review_minutes'] ?? 0),
                'correction_minutes' => (float) ($row->meta['correction_minutes'] ?? 0),
                'incident_minutes' => (float) ($row->meta['incident_minutes'] ?? 0),
                'occurred_at' => Carbon::parse((string) ($row->meta['occurred_at'] ?? '')),
            ])
            ->values();
    }

    /**
     * Агрегат окна: исходы разделены (accepted/minor, substantial-edit,
     * reject — знаменатель включает отказы), sent_n — независимое измерение
     * (не выводится из принятия), минуты по трём категориям. Повтор события
     * (тот же event_key) не растит счётчики.
     *
     * Вход — уже проверенные события ({@see recordEvent()} / {@see eventsForWindow()}).
     *
     * @param  iterable<array{event_key: string, outcome: string, sent?: bool, review_minutes?: int|float, correction_minutes?: int|float, incident_minutes?: int|float}>  $events
     * @return array{window_from: ?string, window_to: ?string, accepted_minor_n: int, substantial_edit_n: int, reject_n: int, total_reviews: int, sent_n: int, review_minutes_total: float, correction_minutes_total: float, incident_minutes_total: float, minutes_total: float, duplicates_ignored: int, total_distinct: int}
     */
    public function tally(iterable $events, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        // Материализуем один раз: дальше события проходят только по счётчикам.
        $events = [...$events];

        $seen = [];
        $duplicates = 0;
        $counts = [
            self::OUTCOME_ACCEPTED_MINOR => 0,
            self::OUTCOME_SUBSTANTIAL_EDIT => 0,
            self::OUTCOME_REJECT => 0,
        ];
        $sent = 0;
        $minutes = [
            self::MINUTE_KIND_REVIEW => 0.0,
            self::MINUTE_KIND_CORRECTION => 0.0,
            self::MINUTE_KIND_INCIDENT => 0.0,
        ];

        foreach ($events as $event) {
            $key = (string) ($event['event_key'] ?? '');

            if (isset($seen[$key])) {
                // Повтор события — счётчики не растут.
                $duplicates++;

                continue;
            }
            $seen[$key] = true;

            $counts[(string) $event['outcome']]++;

            if ((bool) ($event['sent'] ?? false)) {
                $sent++;
            }

            foreach (self::MINUTE_KINDS as $kind) {
                $minutes[$kind] += (float) ($event[$kind.'_minutes'] ?? 0);
            }
        }

        return [
            'window_from' => $from?->toIso8601String(),
            'window_to' => $to?->toIso8601String(),
            'accepted_minor_n' => $counts[self::OUTCOME_ACCEPTED_MINOR],
            'substantial_edit_n' => $counts[self::OUTCOME_SUBSTANTIAL_EDIT],
            'reject_n' => $counts[self::OUTCOME_REJECT],
            'total_reviews' => array_sum($counts),
            'sent_n' => $sent,
            'review_minutes_total' => $minutes[self::MINUTE_KIND_REVIEW],
            'correction_minutes_total' => $minutes[self::MINUTE_KIND_CORRECTION],
            'incident_minutes_total' => $minutes[self::MINUTE_KIND_INCIDENT],
            'minutes_total' => array_sum($minutes),
            'duplicates_ignored' => $duplicates,
            'total_distinct' => count($seen),
        ];
    }

    /**
     * Агрегат окна из журнала событий.
     *
     * @return array{window_from: ?string, window_to: ?string, accepted_minor_n: int, substantial_edit_n: int, reject_n: int, total_reviews: int, sent_n: int, review_minutes_total: float, correction_minutes_total: float, incident_minutes_total: float, minutes_total: float, duplicates_ignored: int, total_distinct: int}
     */
    public function countWindow(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->tally($this->eventsForWindow($from, $to), $from, $to);
    }

    /**
     * Сохраняет агрегат в существующий журнал support_ai_reply_events (meta —
     * только счётчики и ключ окна, без текста учеников). Повторное сохранение
     * того же окна обновляет ту же строку — вторая строка не создаётся.
     *
     * @param  array{window_from: ?string, window_to: ?string, accepted_minor_n: int, substantial_edit_n: int, reject_n: int, total_reviews: int, sent_n: int, review_minutes_total: float, correction_minutes_total: float, incident_minutes_total: float, minutes_total: float, duplicates_ignored: int, total_distinct: int}  $aggregate
     */
    public function recordAggregate(array $aggregate): SupportAiReplyEvent
    {
        $meta = [];
        foreach (self::AGGREGATE_KEYS as $key) {
            $meta[$key] = $aggregate[$key] ?? null;
        }
        $meta['window_key'] = $this->windowKey($aggregate);

        $existing = SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_AGGREGATE)
            ->where('meta->window_key', $meta['window_key'])
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['meta' => $meta])->save();

            return $existing;
        }

        return SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAiReplyEvent::EVENT_DRAFT_REVIEW_AGGREGATE,
            'meta' => $meta,
        ]);
    }

    /**
     * Стабильный ключ окна для идемпотентного сохранения агрегата.
     *
     * @param  array{window_from: ?string, window_to: ?string}  $aggregate
     */
    private function windowKey(array $aggregate): string
    {
        return ($aggregate['window_from'] ?? '').'|'.($aggregate['window_to'] ?? '');
    }
}
