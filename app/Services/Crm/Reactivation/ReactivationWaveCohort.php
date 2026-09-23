<?php

declare(strict_types=1);

namespace App\Services\Crm\Reactivation;

use App\Enums\ReactivationWaveTemplate;
use App\Models\DebtWinBackAttempt;
use App\Models\Payment;
use App\Models\SuppressedEmail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H5288 — когорта волны реактивации A. ТОЛЬКО ЧТЕНИЕ: строит список адресатов
 * и счёт исключений, не пишет ни строки и ничего не отправляет.
 *
 * Две половины когорты (Uprava reports/BUSINESS_HEALTH_ASSESSMENT_2026-09-14.md):
 *   - `non_continuer` — «неплатившие следующий блок» (919): последняя оплата
 *     не старше `continuation_days`, но активного доступа уже нет;
 *   - `lapsed` — реактивационный пул (563): последняя оплата старше
 *     `continuation_days`.
 *
 * Исключения считаются ПОИМЕННО (первая сработавшая причина выигрывает), чтобы
 * сухой отчёт объяснял разницу между «кандидатов» и «в списке»:
 *   active_payer · refund_only · blocked_or_staff · do_not_contact ·
 *   suppressed_email · recently_contacted · no_channel.
 *
 * Пороги — {@see config('reactivation_wave')}, не хардкод.
 */
final class ReactivationWaveCohort
{
    public const SEGMENT_LAPSED = 'lapsed';

    public const SEGMENT_NON_CONTINUER = 'non_continuer';

    public const CHANNEL_TELEGRAM = 'telegram_bot';

    public const CHANNEL_EMAIL = 'email';

    /** Заглушки-адреса импорта: почты по факту нет. */
    private const PLACEHOLDER_EMAIL_SUFFIX = '@no-email.com';

    public function evaluate(?Carbon $now = null): ReactivationWaveCensus
    {
        $now = ($now ?? Carbon::now())->copy();
        $dormantBefore = $now->copy()->subDays($this->days('dormant_days'));
        $continuationBefore = $now->copy()->subDays($this->days('continuation_days'));
        $recentContactAfter = $now->copy()->subDays($this->days('recent_contact_days'));

        $lastPaid = $this->lastPaidAtByUser();
        if ($lastPaid === []) {
            return new ReactivationWaveCensus([], [], 0, $now->toIso8601String());
        }

        $candidates = User::query()
            ->whereIn('id', array_keys($lastPaid))
            ->get(['id', 'name', 'email', 'telegram_id', 'vk_id', 'wants_email_announcements', 'global_status', 'is_unreliable', 'note']);

        $suppressed = $this->suppressedEmailSet($candidates);
        $activeMembers = $this->activeGroupMemberIds($candidates->modelKeys());
        $refundOnly = $this->refundOnlyUserIds($candidates->modelKeys());
        $recentlyContacted = $this->recentlyContactedUserIds($candidates->modelKeys(), $recentContactAfter);
        $excludedStatuses = (array) config('reactivation_wave.excluded_global_statuses', []);
        $noteMarkers = (array) config('reactivation_wave.do_not_contact_note_markers', []);

        $sendList = [];
        $excluded = [];

        foreach ($candidates as $user) {
            $userId = (int) $user->getKey();
            $paidAt = $lastPaid[$userId];

            // 1. Активный плательщик: платил недавно или сидит в живой группе.
            if ($paidAt->greaterThan($dormantBefore) || isset($activeMembers[$userId])) {
                $excluded[$userId] = 'active_payer';

                continue;
            }

            // 2. Все оплаты возвращены — деньги отданы, писать «вернитесь» нельзя.
            if (isset($refundOnly[$userId])) {
                $excluded[$userId] = 'refund_only';

                continue;
            }

            // 3. Служебные/бесплатные карточки и помеченные ненадёжными.
            if ((bool) $user->is_unreliable || in_array((string) $user->global_status, $excludedStatuses, true)) {
                $excluded[$userId] = 'blocked_or_staff';

                continue;
            }

            // 4. Явный отказ от контакта в карточке (в т.ч. «умер», «не писать»).
            if ($this->noteForbidsContact((string) ($user->note ?? ''), $noteMarkers)) {
                $excluded[$userId] = 'do_not_contact';

                continue;
            }

            // 5. Уже писали недавно в win-back — волна не бьёт повторно.
            if (isset($recentlyContacted[$userId])) {
                $excluded[$userId] = 'recently_contacted';

                continue;
            }

            $channel = $this->channelFor($user, $suppressed);
            if ($channel === null) {
                // 6. Канала нет: ни бота, ни живой согласованной почты.
                $excluded[$userId] = $this->emailSuppressed($user, $suppressed) ? 'suppressed_email' : 'no_channel';

                continue;
            }

            $segment = $paidAt->greaterThan($continuationBefore)
                ? self::SEGMENT_NON_CONTINUER
                : self::SEGMENT_LAPSED;

            $sendList[] = [
                'user_id' => $userId,
                'segment' => $segment,
                'channel' => $channel,
                'template' => ReactivationWaveTemplate::forSegment($segment)->value,
                'days_since_payment' => (int) $paidAt->diffInDays($now),
            ];
        }

        return new ReactivationWaveCensus(
            sendList: $sendList,
            excluded: $excluded,
            candidateCount: $candidates->count(),
            generatedAt: $now->toIso8601String(),
        );
    }

    /**
     * Дата последней оплаченной покупки по каждому ученику. Возвраты
     * (`refund_of_payment_id`) в расчёт «когда платил» не берём.
     *
     * @return array<int, Carbon>
     */
    private function lastPaidAtByUser(): array
    {
        $rows = Payment::query()
            ->whereNotNull('user_id')
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNull('refund_of_payment_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(COALESCE(first_paid_at, created_at)) as last_paid_at')
            ->pluck('last_paid_at', 'user_id');

        $out = [];
        foreach ($rows as $userId => $at) {
            if ($at === null) {
                continue;
            }
            $out[(int) $userId] = Carbon::parse((string) $at);
        }

        return $out;
    }

    /**
     * Ученики, которые ПРЯМО СЕЙЧАС учатся: членство без `left_at` в группе со
     * статусом `active` (идёт обучение).
     *
     * Группы `forming` сюда НЕ входят намеренно: набор — это список
     * приглашённых, а не доступ. Сухой прогон 23-09-2026 на проде показал,
     * что в `forming`-ростерах висит 597 давно не плативших учеников (315
     * уснувших + 282 не продолживших) — трактовать их как «активных
     * плательщиков» значило бы выкинуть из волны почти всю когорту.
     *
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function activeGroupMemberIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = DB::table('group_user')
            ->join('groups', 'groups.id', '=', 'group_user.group_id')
            ->whereIn('group_user.user_id', $userIds)
            ->whereNull('group_user.left_at')
            ->where('groups.status', '=', 'active')
            ->distinct()
            ->pluck('group_user.user_id');

        return array_fill_keys(array_map('intval', $ids->all()), true);
    }

    /**
     * Ученики, у которых КАЖДАЯ оплата возвращена: есть возвратные строки на
     * все оплаченные платежи.
     *
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function refundOnlyUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $paidCounts = Payment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNull('refund_of_payment_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as c')
            ->pluck('c', 'user_id');

        $refundedIds = Payment::query()
            ->whereNotNull('refund_of_payment_id')
            ->pluck('refund_of_payment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($refundedIds === []) {
            return [];
        }

        $refundedCounts = Payment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('id', $refundedIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as c')
            ->pluck('c', 'user_id');

        $out = [];
        foreach ($paidCounts as $userId => $paid) {
            $refunded = (int) ($refundedCounts[$userId] ?? 0);
            if ($refunded > 0 && $refunded >= (int) $paid) {
                $out[(int) $userId] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function recentlyContactedUserIds(array $userIds, Carbon $after): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = DebtWinBackAttempt::query()
            ->whereIn('user_id', $userIds)
            ->where('sent_at', '>=', $after)
            ->distinct()
            ->pluck('user_id');

        return array_fill_keys(array_map('intval', $ids->all()), true);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, true>
     */
    private function suppressedEmailSet(Collection $users): array
    {
        $emails = $users
            ->pluck('email')
            ->filter()
            ->map(fn ($email): string => mb_strtolower((string) $email))
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            return [];
        }

        $found = SuppressedEmail::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->map(fn ($email): string => mb_strtolower((string) $email))
            ->all();

        return array_fill_keys($found, true);
    }

    /**
     * Канал волны: бот в приоритете (дешевле и читается чаще), почта —
     * запасной. Почта годится только при согласии, валидном адресе и
     * отсутствии в списке подавления.
     *
     * @param  array<string, true>  $suppressed
     */
    private function channelFor(User $user, array $suppressed): ?string
    {
        if (! empty($user->telegram_id)) {
            return self::CHANNEL_TELEGRAM;
        }

        return $this->emailUsable($user, $suppressed) ? self::CHANNEL_EMAIL : null;
    }

    /** @param array<string, true> $suppressed */
    private function emailUsable(User $user, array $suppressed): bool
    {
        $email = mb_strtolower((string) ($user->email ?? ''));

        return $email !== ''
            && ! str_ends_with($email, self::PLACEHOLDER_EMAIL_SUFFIX)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && (bool) $user->wants_email_announcements
            && ! isset($suppressed[$email]);
    }

    /** @param array<string, true> $suppressed */
    private function emailSuppressed(User $user, array $suppressed): bool
    {
        $email = mb_strtolower((string) ($user->email ?? ''));

        return $email !== '' && isset($suppressed[$email]);
    }

    /** @param list<string> $markers */
    private function noteForbidsContact(string $note, array $markers): bool
    {
        if ($note === '') {
            return false;
        }

        $haystack = mb_strtolower($note);
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($haystack, mb_strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }

    private function days(string $key): int
    {
        return max(0, (int) config('reactivation_wave.'.$key, 0));
    }
}
