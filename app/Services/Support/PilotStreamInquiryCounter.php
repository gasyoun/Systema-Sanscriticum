<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\UnifiedMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H6176 (E026, hermes-24-01a10bca): счётчик обращений пилотного потока
 * 341/79 («Грамматика по Кочергиной, гр. 53», фиксирован на 90 дней —
 * базлайн H6120 §2). Знаменатель недельных метрик программы.
 *
 * Единица учёта — одно входящее сообщение студента (обращение-событие) из
 * СУЩЕСТВУЮЩИХ сторов поддержки, без параллельного механизма записи:
 *  - telegram_support_messages (direction = incoming), ключ события —
 *    натуралистический уникальный ключ строки (account, chat, message_id);
 *  - chat_messages (role = user), ключ события — web:{id}.
 * Привязка пользователя — как в {@see UnifiedMessage}:
 * chat.linked_user_id ?? contact.linked_user_id / chat_messages.user_id.
 *
 * Контракт миссии:
 *  1. считаются обращения ТОЛЬКО course 341 / group 79;
 *  2. повтор события (тот же ключ) не увеличивает N;
 *  3. другой поток не попадает в выборку (своя корзина, вне N);
 *  4. неизвестная принадлежность выделена отдельно;
 *  5. сохраняемые агрегаты — только счётчики, без текста учеников.
 *
 * Правила атрибуции (по состоянию членства на момент обращения, пивот
 * group_user: created_at/left_at):
 *  - pilot: ровно одно активное членство — в пилот-группе 79;
 *  - other_stream: активные членства есть, пилот-группы среди них нет
 *    (чужой поток не попадает в выборку);
 *  - unknown: принадлежность неизвестна/неоднозначна — пользователь не
 *    привязан или не найден, активных членств нет, либо студент состоит
 *    одновременно в пилот-группе и в другом потоке (к какому потоку
 *    отнести обращение — не определить).
 *
 * Никаких production-включений, отправок и платных вызовов: счётчик только
 * читает существующие сторы и пишет тексто-свободный агрегат в существующий
 * журнал support_ai_reply_events (одна строка на окно, повтор — не вторая
 * строка).
 */
final class PilotStreamInquiryCounter
{
    /** Пилот-поток «Грамматика по Кочергиной, гр. 53» (публичный базлайн H6120 §2). */
    public const PILOT_COURSE_ID = 341;

    public const PILOT_GROUP_ID = 79;

    /** Корзины атрибуции обращения. */
    public const BUCKET_PILOT = 'pilot';

    public const BUCKET_OTHER_STREAM = 'other_stream';

    public const BUCKET_UNKNOWN = 'unknown';

    /** Ключи сохраняемого агрегата — ровно счётчики, никакого текста учеников. */
    public const AGGREGATE_KEYS = [
        'course_id',
        'group_id',
        'window_from',
        'window_to',
        'pilot_n',
        'other_stream_n',
        'unknown_attribution_n',
        'duplicates_ignored',
        'total_distinct',
    ];

    public function __construct(
        private readonly int $courseId = self::PILOT_COURSE_ID,
        private readonly int $groupId = self::PILOT_GROUP_ID,
    ) {}

    /**
     * События-обращения окна из обоих существующих сторов поддержки.
     * Дескриптор события БЕЗ текста: ключ, пользователь, время.
     *
     * @return Collection<int, array{key: string, user_id: int|null, occurred_at: CarbonInterface}>
     */
    public function inquiryEventsForWindow(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $telegram = TelegramSupportMessage::query()
            ->with(['chat', 'contact'])
            ->where('direction', 'incoming')
            ->whereBetween('sent_at', [$from, $to])
            ->get()
            ->map(function (TelegramSupportMessage $message): array {
                return [
                    'key' => sprintf(
                        'tg:%d:%d:%d',
                        $message->telegram_support_account_id,
                        $message->telegram_chat_id,
                        $message->telegram_message_id,
                    ),
                    'user_id' => $message->chat?->linked_user_id ?? $message->contact?->linked_user_id,
                    'occurred_at' => $message->sent_at,
                ];
            });

        $web = ChatMessage::query()
            ->where('role', 'user')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'user_id', 'created_at'])
            ->map(fn (ChatMessage $message): array => [
                'key' => 'web:'.$message->id,
                'user_id' => $message->user_id,
                'occurred_at' => $message->created_at,
            ]);

        return $telegram->concat($web)->values();
    }

    /**
     * Агрегат обращений окна. Повтор события (тот же ключ) не увеличивает N.
     *
     * @param  iterable<array{key: string, user_id: int|null, occurred_at: CarbonInterface}>  $events
     * @return array{course_id: int, group_id: int, window_from: ?string, window_to: ?string, pilot_n: int, other_stream_n: int, unknown_attribution_n: int, duplicates_ignored: int, total_distinct: int}
     */
    public function tally(iterable $events, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        // Материализуем один раз: ниже события проходят и для загрузки
        // пользователей, и для подсчёта.
        $events = [...$events];

        $users = $this->loadUsers($events);

        $seen = [];
        $counts = [
            self::BUCKET_PILOT => 0,
            self::BUCKET_OTHER_STREAM => 0,
            self::BUCKET_UNKNOWN => 0,
        ];
        $duplicates = 0;

        foreach ($events as $event) {
            $key = (string) $event['key'];

            if (isset($seen[$key])) {
                // Повтор события — счётчик N не растёт.
                $duplicates++;

                continue;
            }
            $seen[$key] = true;

            $bucket = $this->attribute($event['user_id'] ?? null, $event['occurred_at'], $users);
            $counts[$bucket]++;
        }

        return [
            'course_id' => $this->courseId,
            'group_id' => $this->groupId,
            'window_from' => $from?->toIso8601String(),
            'window_to' => $to?->toIso8601String(),
            'pilot_n' => $counts[self::BUCKET_PILOT],
            'other_stream_n' => $counts[self::BUCKET_OTHER_STREAM],
            'unknown_attribution_n' => $counts[self::BUCKET_UNKNOWN],
            'duplicates_ignored' => $duplicates,
            'total_distinct' => array_sum($counts),
        ];
    }

    /**
     * Агрегат окна из существующих сторов поддержки.
     *
     * @return array{course_id: int, group_id: int, window_from: ?string, window_to: ?string, pilot_n: int, other_stream_n: int, unknown_attribution_n: int, duplicates_ignored: int, total_distinct: int}
     */
    public function countWindow(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->tally($this->inquiryEventsForWindow($from, $to), $from, $to);
    }

    /**
     * Сохраняет агрегат в существующий журнал support_ai_reply_events (meta —
     * только счётчики и идентификаторы потока/окна, без текста учеников).
     * Повторное сохранение того же окна обновляет ту же строку — вторая строка
     * не создаётся, N не растёт.
     *
     * @param  array{course_id: int, group_id: int, window_from: ?string, window_to: ?string, pilot_n: int, other_stream_n: int, unknown_attribution_n: int, duplicates_ignored: int, total_distinct: int}  $aggregate
     */
    public function recordAggregate(array $aggregate): SupportAiReplyEvent
    {
        $meta = [];
        foreach (self::AGGREGATE_KEYS as $key) {
            $meta[$key] = $aggregate[$key] ?? null;
        }
        $meta['window_key'] = $this->windowKey($aggregate);

        $existing = SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE)
            ->where('meta->window_key', $meta['window_key'])
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['meta' => $meta])->save();

            return $existing;
        }

        return SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE,
            'meta' => $meta,
        ]);
    }

    /**
     * Корзина обращения: pilot / other_stream / unknown.
     *
     * @param  Collection<int, User>  $users
     */
    private function attribute(?int $userId, CarbonInterface $occurredAt, Collection $users): string
    {
        if ($userId === null) {
            return self::BUCKET_UNKNOWN;
        }

        $user = $users->get($userId);
        if ($user === null) {
            return self::BUCKET_UNKNOWN;
        }

        $activeGroups = $user->groups
            ->filter(fn ($group): bool => $this->membershipActiveAt($group->pivot, $occurredAt));

        if ($activeGroups->isEmpty()) {
            return self::BUCKET_UNKNOWN;
        }

        $inPilot = $activeGroups->contains(fn ($group): bool => (int) $group->id === $this->groupId);

        if (! $inPilot) {
            return self::BUCKET_OTHER_STREAM;
        }

        // Пилот + чужой поток одновременно: обращение нельзя однозначно
        // отнести к потоку — неизвестная принадлежность, вне N.
        return $activeGroups->count() === 1
            ? self::BUCKET_PILOT
            : self::BUCKET_UNKNOWN;
    }

    /**
     * Активно ли членство в группе на момент обращения: вступление не позже
     * события, выход (left_at) — строго позже события.
     *
     * @param  object{created_at: ?string, left_at: ?string}  $pivot
     */
    private function membershipActiveAt(object $pivot, CarbonInterface $occurredAt): bool
    {
        $at = $occurredAt->getTimestamp();
        $joined = $this->pivotTimestamp($pivot->created_at ?? null);

        if ($joined !== null && $joined > $at) {
            return false;
        }

        $left = $this->pivotTimestamp($pivot->left_at ?? null);

        return $left === null || $left > $at;
    }

    /**
     * Пивот-значение времени (строка или Carbon) → unix-секунды, null если нет.
     */
    private function pivotTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return Carbon::parse((string) $value)->getTimestamp();
    }

    /**
     * Единая загрузка пользователей события с их членствами в группах.
     *
     * @param  iterable<array{key: string, user_id: int|null, occurred_at: CarbonInterface}>  $events
     * @return Collection<int, User>
     */
    private function loadUsers(iterable $events): Collection
    {
        $ids = [];
        foreach ($events as $event) {
            $userId = $event['user_id'] ?? null;
            if ($userId !== null) {
                $ids[(int) $userId] = (int) $userId;
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        return User::query()
            ->with('groups')
            ->whereIn('id', array_values($ids))
            ->get(['id'])
            ->keyBy('id');
    }

    /**
     * Стабильный ключ окна для идемпотентного сохранения агрегата.
     *
     * @param  array{window_from: ?string, window_to: ?string}  $aggregate
     */
    private function windowKey(array $aggregate): string
    {
        return sprintf(
            '%s..%s|%d/%d',
            $aggregate['window_from'] ?? '-',
            $aggregate['window_to'] ?? '-',
            $this->courseId,
            $this->groupId,
        );
    }
}
