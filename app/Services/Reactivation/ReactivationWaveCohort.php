<?php

declare(strict_types=1);

namespace App\Services\Reactivation;

use App\Models\Course;
use App\Models\Payment;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\DebtorsReport;
use Illuminate\Support\Collection;

/**
 * Когорта реактивационной волны A (H5288). Два сегмента из бизнес-среза
 * 25–29-08 (reports/BUSINESS_HEALTH_ASSESSMENT_2026-09-14.md):
 *
 *  • non_continuer (~919 «не платившие следующий блок») — строки DebtorsReport
 *    с debt_type='not_renewed': платил раньше, текущий блок не покрыт.
 *  • lapsed (~563 «реактивационный пул») — платившие студенты БЕЗ текущей
 *    группы: есть хотя бы один реальный paid-платёж, но нет членства в
 *    группе со статусом forming/active.
 *
 * Исключения считаются поимённо (см. EXCLUDED_* в counts): активные плательщики,
 * только-возвраты, исключённые/покинувшие (в схеме нет отдельного флага
 * «умер/заблокирован» — ближайший носитель статуса course_user.status).
 * Канал: бот Telegram там, где есть chat id и согласие на анонсы, иначе email
 * (согласие + не в suppression-листе). НИЧЕГО НЕ ОТПРАВЛЯЕТ — только отчёт.
 */
final class ReactivationWaveCohort
{
    public const SEGMENT_NON_CONTINUER = 'non_continuer';

    public const SEGMENT_LAPSED = 'lapsed';

    public const CHANNEL_TELEGRAM_BOT = 'tg_bot';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_NONE = 'none';

    public function __construct(private DebtorsReport $debtors) {}

    /**
     * @return array{
     *     rows: Collection<int, array{user_id:int, name:string, segment:string, channel:string, tg_ok:bool, email_ok:bool, last_course:?string}>,
     *     counts: array<string, int>
     * }
     */
    public function build(): array
    {
        $nonContinuerIds = $this->debtors
            ->query()
            ->get()
            ->filter(fn ($row) => $row->getAttribute('debt_type') === 'not_renewed')
            ->pluck('id')
            ->unique()
            ->values();

        $payerIds = $this->realPayerIds();
        $activeIds = $this->activeGroupUserIds();
        $blockedIds = $this->usersExpelledEverywhere();

        // Lapsed-пул: реальный плательщик, не «не продливший» (его уже взяли),
        // не в действующей группе, не исключён везде.
        $lapsedIds = $payerIds
            ->diff($nonContinuerIds)
            ->diff($activeIds)
            ->diff($blockedIds)
            ->values();

        $counts = array_fill_keys([
            'channel_'.self::CHANNEL_TELEGRAM_BOT,
            'channel_'.self::CHANNEL_EMAIL,
            'channel_'.self::CHANNEL_NONE,
            self::SEGMENT_NON_CONTINUER.'_channel_'.self::CHANNEL_TELEGRAM_BOT,
            self::SEGMENT_NON_CONTINUER.'_channel_'.self::CHANNEL_EMAIL,
            self::SEGMENT_NON_CONTINUER.'_channel_'.self::CHANNEL_NONE,
            self::SEGMENT_LAPSED.'_channel_'.self::CHANNEL_TELEGRAM_BOT,
            self::SEGMENT_LAPSED.'_channel_'.self::CHANNEL_EMAIL,
            self::SEGMENT_LAPSED.'_channel_'.self::CHANNEL_NONE,
            'opt_out_messenger',
            'opt_out_or_suppressed_email',
        ], 0);

        $counts += [
            'non_continuer' => $nonContinuerIds->count(),
            'lapsed' => $lapsedIds->count(),
            'total' => $nonContinuerIds->count() + $lapsedIds->count(),

            // Исключения (считаются от исходного пула «платил хоть раз реально
            // или числился не продлившим»): активные плательщики, только-возвраты,
            // исключённые/покинувшие по всем курсам.
            'excluded_active_payers' => $payerIds->diff($nonContinuerIds)->intersect($activeIds)->count(),
            'excluded_refund_only' => $this->refundOnlyUserIds()->diff($nonContinuerIds)->count(),
            'excluded_expelled_or_left' => $payerIds->diff($nonContinuerIds)->diff($activeIds)->intersect($blockedIds)->count(),
        ];

        $rows = collect();
        $segmentOf = collect([
            self::SEGMENT_NON_CONTINUER => $nonContinuerIds,
            self::SEGMENT_LAPSED => $lapsedIds,
        ]);

        foreach ($segmentOf as $segment => $ids) {
            foreach ($ids as $userId) {
                $channel = $this->channelFor($userId);
                $counts['channel_'.$channel]++;
                $counts[$segment.'_channel_'.$channel]++;
                $rows->push([
                    'user_id' => (int) $userId,
                    'name' => '',
                    'segment' => $segment,
                    'channel' => $channel,
                    'tg_ok' => false,
                    'email_ok' => false,
                    'last_course' => null,
                ]);
            }
        }

        // Дозаполняем поля и считаем канальные отказы одним проходом по юзерам.
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        foreach ($rows as &$row) {
            $user = $users->get($row['user_id']);
            if ($user === null) {
                continue;
            }
            $row['name'] = (string) $user->name;
            $row['tg_ok'] = $this->tgAvailable($user);
            $row['email_ok'] = $this->emailAvailable($user);
            $row['last_course'] = $this->lastCourseTitle($row['user_id']);
            if ($user->telegram_id && ! $row['tg_ok']) {
                $counts['opt_out_messenger']++;
            }
            if ($user->email && ! $row['email_ok']) {
                $counts['opt_out_or_suppressed_email']++;
            }
        }
        unset($row);

        $counts['channel_total'] = $counts['channel_'.self::CHANNEL_TELEGRAM_BOT]
            + $counts['channel_'.self::CHANNEL_EMAIL]
            + $counts['channel_'.self::CHANNEL_NONE];

        return ['rows' => $rows->sortBy('user_id')->values(), 'counts' => $counts];
    }

    /** Первичный канал: бот Telegram при живом chat id, иначе email. */
    public function channelFor(int $userId): string
    {
        $user = User::find($userId);

        if ($user !== null && $this->tgAvailable($user)) {
            return self::CHANNEL_TELEGRAM_BOT;
        }
        if ($user !== null && $this->emailAvailable($user)) {
            return self::CHANNEL_EMAIL;
        }

        return self::CHANNEL_NONE;
    }

    /** TG-канал: привязанный чат + согласие на анонсы в мессенджерах (152-ФЗ). */
    private function tgAvailable(User $user): bool
    {
        return ! empty($user->telegram_id) && (bool) $user->wants_messenger_announcements;
    }

    /** Email-канал: валидный адрес + согласие + не в suppression-листе. */
    private function emailAvailable(User $user): bool
    {
        return $user->email !== null
            && filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false
            && (bool) $user->wants_email_announcements
            && ! SuppressedEmail::isSuppressed((string) $user->email);
    }

    /**
     * Реальные плательщики: paid/success, не условный, не возврат («Расход») и
     * не выплата ЗП — те же фильтры, что у DebtorsReport (одна правда о «платил»).
     *
     * @return Collection<int, int>
     */
    private function realPayerIds(): Collection
    {
        return Payment::query()
            ->where('is_conditional', false)
            ->whereNotIn('tariff', ['Расход', 'salary_payout'])
            ->whereIn('status', Payment::PAID_STATUSES)
            ->distinct()
            ->pluck('user_id');
    }

    /**
     * Юзеры, чьи «платежи» — только возвраты: реальной оплаты не было вовсе.
     *
     * @return Collection<int, int>
     */
    private function refundOnlyUserIds(): Collection
    {
        $refundIds = Payment::query()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where(function ($q) {
                $q->where('tariff', 'Расход')->orWhereNotNull('refund_of_payment_id');
            })
            ->pluck('user_id')
            ->unique();

        return $refundIds->diff($this->realPayerIds())->values();
    }

    /**
     * Членство в действующей группе (forming/active) = «активный студент».
     *
     * @return Collection<int, int>
     */
    private function activeGroupUserIds(): Collection
    {
        return User::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.status', ['forming', 'active']))
            ->pluck('id');
    }

    /**
     * Исключён/покинул по ВСЕМ курсам (course_user.status ∈ Покинул/Исключен).
     * Отдельного флага «умер/заблокирован» в схеме нет — это его ближайший носитель.
     *
     * @return Collection<int, int>
     */
    private function usersExpelledEverywhere(): Collection
    {
        return User::query()
            ->whereHas('courses', fn ($q) => $q->whereIn('course_user.status', ['Исключен', 'Покинул']))
            ->whereDoesntHave('courses', fn ($q) => $q->where(function ($w) {
                $w->whereNull('course_user.status')
                    ->orWhereNotIn('course_user.status', ['Исключен', 'Покинул']);
            }))
            ->pluck('id');
    }

    private function lastCourseTitle(int $userId): ?string
    {
        $courseId = Payment::query()
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNotIn('tariff', ['Расход', 'salary_payout'])
            ->where('user_id', $userId)
            ->max('course_id');

        return $courseId !== null ? (string) Course::query()->whereKey($courseId)->value('title') : null;
    }
}
