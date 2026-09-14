<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\User;
use App\Support\ClubFreeTierSrsDeck;
use Illuminate\Support\Facades\Log;

/**
 * Бесплатный уровень «Свободный» (H2644, спецификация §6): раз в месяц — один
 * урок из курса, за который студент КОГДА-ТО ПЛАТИЛ, грант на 30 дней.
 *
 * Механика гранта уже есть (LessonAccessGrant: user·lesson·reason·expires_at,
 * отзываемый и срочный; на проде 2 строки, обе руками). Не хватало ровно
 * выдающего — им и является этот класс.
 *
 * ── Правило выбора урока (решение, а не догадка) ────────────────────────────
 *
 * Кандидат — урок, который студент ЕЩЁ НЕ МОЖЕТ смотреть. Иначе подарок
 * пустой: платившие сохраняют доступ к оплаченному навсегда, и «урок из
 * купленного курса» без этого условия вернул бы человеку то, что у него уже
 * есть. Целевая аудитория уровня — уснувшие плательщики (350 человек,
 * D6 = вариант «а»), а у них типовая картина — оплачен блок 1, не оплачены 2–3.
 *
 * Из кандидатов берём ПЕРВЫЙ ПО ПОРЯДКУ КУРСА (block_number, sort_order) в том
 * курсе, за который платили ПОСЛЕДНИМ. Обоснование: следующий по программе
 * урок продолжает то, на чём человек остановился, — он понятен без контекста,
 * которого у бросившего курс уже нет; «самый свежий» или случайный урок падает
 * в середину незнакомого материала и работает как реклама, а не как учёба.
 * Последний оплаченный курс выбран потому, что он же и есть точка, где студент
 * ушёл.
 *
 * Урок обязан иметь запись (hasVideo): грант на урок без видео — это выданное
 * право смотреть пустую страницу.
 *
 * ── Накопление ─────────────────────────────────────────────────────────────
 *
 * НЕ копится. Пока жив хоть один грант бесплатного уровня, новый не выдаётся.
 * Неиспользованный месяц сгорает. Иначе вернувшийся через год человек получает
 * двенадцать уроков разом, и приманка возврата превращается в бесплатный архив
 * — то есть ровно в то, что продаётся за ₽1 500 в клубе.
 *
 * ── Исчерпание ─────────────────────────────────────────────────────────────
 *
 * Когда закрытых уроков в оплаченных курсах не осталось, статус — `exhausted`.
 * Подставлять курс, за который человек НЕ платил, нельзя: это уже не «уровень
 * Свободный», а раздача чужого товара.
 */
final class FreeTierLessonGranter
{
    /**
     * Общий префикс всех причин бесплатного уровня. Кампания (H2566) шлёт свою
     * метку через --reason, но запрет на накопление считает по префиксу —
     * иначе кампания и демон выдали бы по гранту каждый.
     */
    public const REASON_PREFIX = 'free_tier';

    public function __construct(private readonly ClubEntitlement $entitlement) {}

    public function enabled(): bool
    {
        return (bool) config('features.membership_free_tier', false);
    }

    public function defaultReason(): string
    {
        return (string) config('membership.free_tier.reason', 'free_tier_monthly');
    }

    /**
     * Кандидаты в выдачу: платившие хотя бы раз, без клуба, без живого гранта.
     * Порядок — по давности последнего платежа (сначала самые уснувшие).
     *
     * @return list<int>
     */
    public function eligibleUserIds(int $limit = 200): array
    {
        $lastPaidAt = Payment::query()
            ->selectRaw('user_id, MAX(created_at) as last_paid_at')
            ->paid()
            ->real()
            ->whereNotNull('course_id')
            ->whereNotNull('user_id')
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->groupBy('user_id')
            ->orderBy('last_paid_at')
            ->limit(max(1, $limit) * 4) // запас: часть отсеется клубом/грантом
            ->pluck('user_id');

        $ids = [];
        foreach ($lastPaidAt as $userId) {
            $user = User::find($userId);
            if (! $user instanceof User) {
                continue;
            }
            if ($this->skipReason($user) !== null) {
                continue;
            }
            $ids[] = (int) $userId;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Почему этому пользователю не выдаём. NULL — выдаём.
     * Клуб отсеиваем первым: у клубного члена и так открыта вся полка,
     * и грант поверх неё — мусорная строка в аудите доступа.
     */
    public function skipReason(User $user): ?string
    {
        if ($this->entitlement->isMember($user)) {
            return 'club_member';
        }

        if ($this->hasLiveFreeTierGrant($user)) {
            return 'has_active_grant';
        }

        if ($this->paidCourseIdsFor($user) === []) {
            return 'never_paid';
        }

        return null;
    }

    public function hasLiveFreeTierGrant(User $user): bool
    {
        return LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('reason', 'like', self::REASON_PREFIX.'%')
            ->active()
            ->exists();
    }

    /**
     * Курсы, за которые платили, — от самого свежего платежа к старым.
     *
     * @return list<int>
     */
    public function paidCourseIdsFor(User $user): array
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->paid()
            ->real()
            ->whereNotNull('course_id')
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->orderByDesc('created_at')
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Оплаченные ключи доступа студента по конкретному курсу. */
    private function ownedKeysFor(User $user, int $courseId): array
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->paid()
            ->pluck('tariff')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Урок к выдаче по правилу выше. NULL — выдавать нечего (исчерпано).
     */
    public function pickLessonFor(User $user): ?Lesson
    {
        $grantedLessonIds = LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->pluck('lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($this->paidCourseIdsFor($user) as $courseId) {
            $ownedKeys = $this->ownedKeysFor($user, $courseId);

            $lessons = Lesson::query()
                ->where('course_id', $courseId)
                ->where('is_published', true)
                ->where('is_free', false)
                ->where('is_preview', false)
                ->whereNotIn('id', $grantedLessonIds)
                ->orderBy('block_number')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($lessons as $lesson) {
                if (! $lesson->hasVideo()) {
                    continue;
                }
                // Уже открыт оплаченным ключом — не подарок.
                if ($lesson->isUnlockedBy($ownedKeys)) {
                    continue;
                }

                return $lesson;
            }
        }

        return null;
    }

    /**
     * Выдать одному человеку.
     *
     * @return array{user_id:int, status:string, lesson_id:?int, course_id:?int, expires_at:?string, lesson_title:?string}
     */
    public function grantFor(User $user, bool $apply, ?string $reason = null): array
    {
        $row = [
            'user_id' => (int) $user->id,
            'status' => 'skipped',
            'lesson_id' => null,
            'course_id' => null,
            'expires_at' => null,
            'lesson_title' => null,
        ];

        $skip = $this->skipReason($user);
        if ($skip !== null) {
            $row['status'] = $skip;

            return $row;
        }

        $lesson = $this->pickLessonFor($user);
        if (! $lesson instanceof Lesson) {
            $row['status'] = 'exhausted';

            return $row;
        }

        $days = max(1, (int) config('membership.free_tier.grant_days', 30));
        $expiresAt = now()->addDays($days);

        $row['status'] = $apply ? 'granted' : 'would_grant';
        $row['lesson_id'] = (int) $lesson->id;
        $row['course_id'] = (int) $lesson->course_id;
        $row['lesson_title'] = (string) $lesson->title;
        $row['expires_at'] = $expiresAt->toDateTimeString();

        if (! $apply) {
            return $row;
        }

        LessonAccessGrant::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'reason' => $reason ?: $this->defaultReason(),
            'granted_by' => null,
            'granted_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        Log::info('free-tier: выдан месячный урок', [
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'reason' => $reason ?: $this->defaultReason(),
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        return $row;
    }

    /**
     * H3643 guest /register: Free-tier of the club with zero payments.
     *
     * grantFor() skips never-paid users (skipReason = never_paid) — that path
     * is a monthly lesson from a course they already bought. A guest has no
     * payments, so signup writes MembershipTier::Free via ClubMembership
     * (source guest_register, payment_id null) and seeds the persistent SRS
     * deck used for this Free-tier.
     *
     * Fail-closed without membership_tiered: ClubEntitlement treats any live
     * ClubMembership as Club when the tier column is not consulted, which
     * would grant recordings. The user and SRS deck are still created.
     *
     * @return array{status:string, membership_id:?int, srs_deck_id:int}
     */
    public function grantSignupFor(User $user): array
    {
        $deck = ClubFreeTierSrsDeck::deckFor($user);
        $row = [
            'status' => 'skipped',
            'membership_id' => null,
            'srs_deck_id' => (int) $deck->id,
        ];

        $memberships = app(ClubMembershipService::class);
        $existing = $memberships->activeFor($user);
        if ($existing instanceof ClubMembership) {
            $row['status'] = 'already_member';
            $row['membership_id'] = (int) $existing->id;

            return $row;
        }

        if (! (bool) config('features.membership_tiered', false)) {
            Log::info('guest-register: skipped Free-tier period — membership_tiered off', [
                'user_id' => $user->id,
            ]);
            $row['status'] = 'tiered_off';

            return $row;
        }

        $membership = $memberships->grantManualPeriod(
            $user,
            1,
            ClubMembership::SOURCE_GUEST_REGISTER,
            MembershipTier::Free,
        );

        Log::info('guest-register: Free-tier period granted', [
            'user_id' => $user->id,
            'membership_id' => $membership->id,
            'srs_deck_id' => $deck->id,
        ]);

        $row['status'] = 'granted';
        $row['membership_id'] = (int) $membership->id;

        return $row;
    }
}
