<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Недельный разбор пробы автоответов H3380 (H3392): «разбираем что пошло
 * не так» без ручного SQL. Агрегат последних N дней support_ai_reply_events,
 * исходящие telegram_support_messages — только чтобы ответить на вопрос
 * «а что было дальше» (человеческий ответ, латентность). Без имён студентов.
 *
 * Пустая неделя — ЯВНАЯ строка «нет активности», не молчаливое пустое
 * сообщение: отсутствие событий тоже факт пробы.
 */
final class AutoReplyWeeklyReport
{
    /** Виды автоответов, по которым меряем медиану человеческого ответа. */
    private const LATENCY_KINDS = ['ack', 'template'];

    /**
     * H6767: окно разведочных прокси «а что было дальше» после отправленного
     * варианта (студент написал снова / куратор ответил). Разведочно: никакой
     * значимости, пороги — решение MG (GTD-остаток, как в H6732 unit 1).
     */
    private const VARIANT_FOLLOWUP_WINDOW_HOURS = 48;

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     total: int,
     *     sent_by_kind: array<string, int>,
     *     categories: array<string, int>,
     *     hinted: int,
     *     hinted_without_answer: int,
     *     stale_skips: int,
     *     latency_median_minutes: array<string, int>,
     *     web_total: int,
     *     web_sent_by_kind: array<string, int>,
     *     text: string
     * }
     */
    public function build(int $days = 7): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $to = CarbonImmutable::now($tz);
        $from = $to->subDays(max(1, $days));

        $events = SupportAiReplyEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get(['id', 'telegram_support_message_id', 'event_type', 'meta', 'created_at']);

        // H5450: веб-срез (samskrte.ru) идёт отдельной строкой отчёта, а не в
        // общую TG-полосу: иные channel'ы — иные знаменатели. Неделя без веб-
        // событий не меняет ни одной прежней строки.
        $webEvents = $events->filter(fn (SupportAiReplyEvent $e): bool => $this->isWebEvent($e));
        $tgEvents = $events->reject(fn (SupportAiReplyEvent $e): bool => $this->isWebEvent($e));

        $webSentByKind = $webEvents
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->groupBy(fn (SupportAiReplyEvent $e): string => (string) ($e->meta['kind'] ?? 'unknown'))
            ->map(fn (Collection $rows): int => $rows->count())
            ->sortKeys()
            ->all();

        $sentByKind = $this->sentByKind($tgEvents);
        $categories = $this->categoryCounts($tgEvents);
        $hinted = $tgEvents->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count();
        $hintedWithoutAnswer = $this->hintedWithoutAnswer($tgEvents);
        $staleSkips = $tgEvents->where('event_type', SupportDmAutoReply::EVENT_STALE_SKIP)->count();
        $latency = $this->humanAnswerLatencyMinutes($tgEvents);

        // H5452: видимость шума — сколько подсказок куратор НЕ получил,
        // потому что сообщение отфильтровано (сервис-чат / инфра-префикс).
        // Каждое подавление = событие dm_hint_suppressed с reason.
        $hintSuppressed = $tgEvents->where('event_type', SupportDmAutoReply::EVENT_HINT_SUPPRESSED)->count();
        $hintSuppressedByReason = $tgEvents
            ->where('event_type', SupportDmAutoReply::EVENT_HINT_SUPPRESSED)
            ->groupBy(fn (SupportAiReplyEvent $e): string => (string) ($e->meta['reason'] ?? 'unknown'))
            ->map(fn (Collection $rows): int => $rows->count())
            ->sortKeys()
            ->all();

        // H6767: разведочный срез по вариантам (support_reply_variant_sampling).
        // Флаг OFF неделю = в meta нет variant_* → среза нет и строка в тексте
        // не появляется (OFF-инвариант отчёта: байт-в-байт прежний).
        $variants = $this->variantAggregates($tgEvents);

        return [
            'from' => $from->format('d.m'),
            'to' => $to->format('d.m'),
            'total' => $events->count(),
            'sent_by_kind' => $sentByKind,
            'categories' => $categories,
            'hinted' => $hinted,
            'hinted_without_answer' => $hintedWithoutAnswer,
            'stale_skips' => $staleSkips,
            'latency_median_minutes' => $latency,
            'web_total' => $webEvents->count(),
            'web_sent_by_kind' => $webSentByKind,
            'hint_suppressed' => $hintSuppressed,
            'hint_suppressed_by_reason' => $hintSuppressedByReason,
            'variants' => $variants,
            'text' => $this->formatHtml(
                $from,
                $to,
                $events->count(),
                $sentByKind,
                $categories,
                $hinted,
                $hintedWithoutAnswer,
                $staleSkips,
                $latency,
                $webEvents->count(),
                $webSentByKind,
                $hintSuppressed,
                $hintSuppressedByReason,
                $variants,
            ),
        ];
    }

    /**
     * Событие веб-полосы: без TG-сообщения и с via вебчат-конвейера.
     */
    private function isWebEvent(SupportAiReplyEvent $event): bool
    {
        return $event->telegram_support_message_id === null
            && (string) ($event->meta['via'] ?? '') === SupportWebchatAutoReply::VIA;
    }

    /**
     * Автоответы по видам (facts / template / ack / greeting).
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @return array<string, int>
     */
    private function sentByKind(Collection $events): array
    {
        return $events
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->groupBy(fn (SupportAiReplyEvent $e): string => (string) ($e->meta['kind'] ?? 'unknown'))
            ->map(fn (Collection $rows): int => $rows->count())
            ->sortKeys()
            ->all();
    }

    /**
     * Категории вопросов по событиям, где категория известна (sent/hinted).
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @return array<string, int>
     */
    private function categoryCounts(Collection $events): array
    {
        return $events
            ->whereIn('event_type', [SupportDmAutoReply::EVENT_SENT, SupportDmAutoReply::EVENT_HINTED])
            ->map(fn (SupportAiReplyEvent $e): ?string => isset($e->meta['category']) ? (string) $e->meta['category'] : null)
            ->filter(fn (?string $category): bool => $category !== null && $category !== '')
            ->countBy()
            ->sortDesc()
            ->all();
    }

    /**
     * Подсказки кураторам, после которых человек так и не ответил
     * (ни одного человеческого исходящего в чате после события).
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     */
    private function hintedWithoutAnswer(Collection $events): int
    {
        return $events
            ->where('event_type', SupportDmAutoReply::EVENT_HINTED)
            ->filter(fn (SupportAiReplyEvent $e): bool => ! $this->hasHumanOutgoingAfter(
                $this->chatIdOf($e),
                $e->created_at ?? null,
            ))
            ->count();
    }

    /**
     * Медиана минут до следующего человеческого исходящего в чате после
     * каждого автоответа вида ack/template.
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @return array<string, int>
     */
    private function humanAnswerLatencyMinutes(Collection $events): array
    {
        $minutes = [];

        foreach ($events->where('event_type', SupportDmAutoReply::EVENT_SENT) as $event) {
            $kind = (string) ($event->meta['kind'] ?? '');

            if (! in_array($kind, self::LATENCY_KINDS, true)) {
                continue;
            }

            /** @var TelegramSupportMessage|null $botOutgoing */
            $botOutgoing = $event->telegram_support_message_id !== null
                ? TelegramSupportMessage::query()->find($event->telegram_support_message_id)
                : null;

            if ($botOutgoing === null || $botOutgoing->sent_at === null) {
                continue;
            }

            $nextHuman = TelegramSupportMessage::query()
                ->where('telegram_chat_id', $botOutgoing->telegram_chat_id)
                ->where('direction', 'outgoing')
                ->where('id', '!=', $botOutgoing->id)
                ->where('sent_at', '>', $botOutgoing->sent_at)
                ->where(fn ($q) => $q
                    ->where('responder_type', 'human')
                    ->orWhere(fn ($qq) => $qq->whereNull('responder_type')->where('role', 'human')))
                ->orderBy('sent_at')
                ->first(['sent_at']);

            if ($nextHuman !== null && $nextHuman->sent_at !== null) {
                $minutes[$kind][] = (int) round($botOutgoing->sent_at->diffInMinutes($nextHuman->sent_at));
            }
        }

        return collect($minutes)
            ->map(fn (array $values): int => $this->median($values))
            ->sortKeys()
            ->all();
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return (int) $values[$mid];
        }

        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function chatIdOf(SupportAiReplyEvent $event): ?int
    {
        if ($event->telegram_support_message_id === null) {
            return null;
        }

        /** @var TelegramSupportMessage|null $message */
        $message = TelegramSupportMessage::query()
            ->find($event->telegram_support_message_id, ['telegram_chat_id']);

        return $message?->telegram_chat_id;
    }

    private function hasHumanOutgoingAfter(?int $chatId, ?\DateTimeInterface $after): bool
    {
        if ($chatId === null || $after === null) {
            return false;
        }

        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $chatId)
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $after)
            ->where(fn ($q) => $q
                ->where('responder_type', 'human')
                ->orWhere(fn ($qq) => $qq->whereNull('responder_type')->where('role', 'human')))
            ->exists();
    }

    /**
     * H6767: разведочный агрегат по вариантам отправленных автоответов.
     *
     * Только id/счётчики (variant_id + sent + два прокси «а что было дальше»
     * в окне 48ч: студент написал снова в чате / куратор ответил). Ни текстов,
     * ни значимости: выборка разведочная, пороги — GTD-остаток MG (та же
     * позиция, что у QA-сэмплинга H6732 unit 1).
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @return array<string, array<string, array{sent: int, student_followup_48h: int, curator_answer_48h: int}>>
     */
    private function variantAggregates(Collection $events): array
    {
        $variants = [];

        foreach ($events->where('event_type', SupportDmAutoReply::EVENT_SENT) as $event) {
            $kind = $event->meta['variant_kind'] ?? null;
            $id = $event->meta['variant_id'] ?? null;

            if ($kind === null || $id === null) {
                continue;
            }

            $kind = (string) $kind;
            $id = (string) $id;

            $variants[$kind] ??= [];
            $variants[$kind][$id] ??= ['sent' => 0, 'student_followup_48h' => 0, 'curator_answer_48h' => 0];
            $variants[$kind][$id]['sent']++;

            /** @var TelegramSupportMessage|null $botOutgoing */
            $botOutgoing = $event->telegram_support_message_id !== null
                ? TelegramSupportMessage::query()->find($event->telegram_support_message_id)
                : null;

            if ($botOutgoing === null || $botOutgoing->sent_at === null) {
                continue;
            }

            $until = $botOutgoing->sent_at->copy()->addHours(self::VARIANT_FOLLOWUP_WINDOW_HOURS);

            $studentFollowUp = TelegramSupportMessage::query()
                ->where('telegram_chat_id', $botOutgoing->telegram_chat_id)
                ->where('direction', 'incoming')
                ->where('sent_at', '>', $botOutgoing->sent_at)
                ->where('sent_at', '<=', $until)
                ->exists();

            $curatorAnswer = TelegramSupportMessage::query()
                ->where('telegram_chat_id', $botOutgoing->telegram_chat_id)
                ->where('direction', 'outgoing')
                ->where('id', '!=', $botOutgoing->id)
                ->where('sent_at', '>', $botOutgoing->sent_at)
                ->where('sent_at', '<=', $until)
                ->where(fn ($q) => $q
                    ->where('responder_type', 'human')
                    ->orWhere(fn ($qq) => $qq->whereNull('responder_type')->where('role', 'human')))
                ->exists();

            if ($studentFollowUp) {
                $variants[$kind][$id]['student_followup_48h']++;
            }

            if ($curatorAnswer) {
                $variants[$kind][$id]['curator_answer_48h']++;
            }
        }

        foreach ($variants as &$byId) {
            ksort($byId);
        }
        unset($byId);

        ksort($variants);

        return $variants;
    }

    /**
     * @param  array<string, int>  $sentByKind
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $latency
     * @param  array<string, int>  $webSentByKind
     * @param  array<string, array<string, array{sent: int, student_followup_48h: int, curator_answer_48h: int}>>  $variants
     */
    private function formatHtml(
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $total,
        array $sentByKind,
        array $categories,
        int $hinted,
        int $hintedWithoutAnswer,
        int $staleSkips,
        array $latency,
        int $webTotal = 0,
        array $webSentByKind = [],
        int $hintSuppressed = 0,
        array $hintSuppressedByReason = [],
        array $variants = [],
    ): string {
        $lines = [
            '<b>🤖 Автоответы · '.$from->format('d.m').'–'.$to->format('d.m').'</b>',
        ];

        if ($total === 0) {
            $lines[] = '';
            $lines[] = 'Активности за неделю нет — проба молчала весь период.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Событий всего: '.$total;

        if ($sentByKind !== []) {
            $kinds = implode(' · ', array_map(
                fn (string $kind, int $n): string => e($kind).' '.$n,
                array_keys($sentByKind),
                array_values($sentByKind),
            ));
            $lines[] = 'Автоответов: '.array_sum($sentByKind).' ('.$kinds.')';
        } else {
            $lines[] = 'Автоответов: 0';
        }

        if ($categories !== []) {
            $cats = implode(', ', array_map(
                fn (string $category, int $n): string => e($category).' '.$n,
                array_keys($categories),
                array_values($categories),
            ));
            $lines[] = 'Категории: '.$cats;
        }

        if ($hinted > 0) {
            $lines[] = 'Подсказок куратору: '.$hinted.' (без ответа: '.$hintedWithoutAnswer.')';
        }

        if ($staleSkips > 0) {
            $lines[] = 'Пропущено как устаревшие: '.$staleSkips.' (backlog era)';
        }

        // H5452: строка шума. Нет подавлений — строки нет, отчёт байт-в-байт
        // прежний (OFF-инвариант).
        if ($hintSuppressed > 0) {
            $reasons = implode(' · ', array_map(
                fn (string $reason, int $n): string => e($reason).' '.$n,
                array_keys($hintSuppressedByReason),
                array_values($hintSuppressedByReason),
            ));
            $lines[] = 'Отфильтровано как шум: '.$hintSuppressed.($reasons !== '' ? ' ('.$reasons.')' : '');
        }

        if ($webTotal > 0) {
            $webKinds = implode(' · ', array_map(
                fn (string $kind, int $n): string => e($kind).' '.$n,
                array_keys($webSentByKind),
                array_values($webSentByKind),
            ));
            $lines[] = 'Веб-чат (samskrte.ru): '.$webTotal.($webKinds !== '' ? ' ('.$webKinds.')' : '');
        }

        if ($latency !== []) {
            $parts = implode(' · ', array_map(
                fn (string $kind, int $minutes): string => e($kind).' ~'.$minutes.' мин',
                array_keys($latency),
                array_values($latency),
            ));
            $lines[] = 'Медиана ответа человека: '.$parts;
        }

        // H6767: срез вариантов. Разведочно, только счётчики; нет вариантов —
        // нет строки (OFF-инвариант: неделя без сэмплинга не меняет отчёт).
        if ($variants !== []) {
            foreach ($variants as $kind => $byId) {
                $parts = implode(' · ', array_map(
                    fn (string $id, array $agg): string => '#'.e($id)
                        .' ×'.$agg['sent']
                        .' (студент 48ч '.$agg['student_followup_48h']
                        .' · куратор 48ч '.$agg['curator_answer_48h'].')',
                    array_keys($byId),
                    array_values($byId),
                ));
                $lines[] = 'Варианты '.e($kind).' (разведочно, пороги — решение MG): '.$parts;
            }
        }

        return implode("\n", $lines);
    }
}
