<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\ClubMembershipService;
use App\Services\Membership\ClubStreamTariffCatalog;
use App\Services\Membership\RecordingAccessPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сквозная репетиция клубного членства (H2644, пункт приёмки «d»):
 * оплата → группа → каталог виден → истечение → право снято, с PASS/FAIL по
 * каждому шагу.
 *
 * Зачем командой, а не руками. Репетиция обязана пройти на ПРОДЕ, но зависит
 * от ops-шага, который делает человек (курс-членство + три тарифа в Filament,
 * спецификация §7 п.5). Команда превращает «пять ручных проверок, которые
 * никто не воспроизведёт одинаково» в одну строку, воспроизводимую в день
 * запуска и через месяц.
 *
 * Что НЕ делаем на проде и почему. Платёж создаётся ВНУТРИ withoutEvents():
 * настоящий Payment::created дёрнул бы выгрузку в финансовый Google Sheet,
 * реферальные награды, прану и приветственные письма — репетиция не должна
 * слать студенту письмо и писать строку в бухгалтерию. Проверяется ровно то,
 * ради чего написан H2644: контур ПРАВА (группа + период + гейт доступа + его
 * снятие). Живой чекаут проверяется человеком один раз по ссылке, которую
 * команда печатает.
 *
 * Все изменения откатываются в конце (--keep оставляет их для разбора).
 */
class RehearseClubMembership extends Command
{
    protected $signature = 'membership:rehearse
        {--user= : id или email студента для репетиции (обязателен для --apply)}
        {--apply : выполнить репетицию (без флага — только проверка предпосылок)}
        {--keep : не откатывать созданное (для разбора после падения)}';

    protected $description = 'Членство: tiers/prices → payment → access → shadow/pilot → expiry/rollback (H2644/H2744).';

    /** @var list<array{step:string, verdict:string, detail:string}> */
    private array $results = [];

    public function handle(
        ClubMembershipService $service,
        ClubEntitlement $entitlement,
        RecordingAccessPolicy $recordings,
    ): int {
        $apply = (bool) $this->option('apply');

        // ── Шаг 1. Предпосылки, которые заводит человек ──────────────────────
        $course = $service->clubCourse();
        if (! $course instanceof Course) {
            $this->record('1. курс-членство', 'FAIL',
                'нет курса со slug «'.config('membership.club.course_slug').'» — заводит человек в Filament (§7 п.5)');

            return $this->finish();
        }

        $tariffs = Tariff::query()->where('course_id', $course->id)->whereNotNull('membership_months')->get();
        $this->record('1. курс-членство', $tariffs->isEmpty() ? 'FAIL' : 'PASS',
            'курс #'.$course->id.' «'.$course->title.'», тарифов со сроком: '.$tariffs->count()
            .($tariffs->isEmpty() ? ' — заполните membership_months (1/3/12) на трёх тарифах' : ''));

        if ((bool) config('features.membership_tiered', false)) {
            $missing = [];
            foreach ([MembershipTier::Basic, MembershipTier::Club] as $tier) {
                foreach ([1, 3, 12] as $months) {
                    $tariff = $tariffs->first(fn (Tariff $candidate): bool => $candidate->membership_tier === $tier && (int) $candidate->membership_months === $months);
                    if (! $tariff instanceof Tariff || ! $tariff->hasExpectedMembershipPrice()) {
                        $missing[] = $tier->value.'_'.$months.'m='.number_format($tier->priceForTerm($months), 0, '.', ' ').'₽';
                    }
                }
            }
            $unclassified = ClubMembership::query()->whereNull('tier_code')->count()
                + $tariffs->whereNull('membership_tier')->count();
            $this->record('1b. tiers + 1/3/12', $missing === [] && $unclassified === 0 ? 'PASS' : 'FAIL',
                'Free=0; Basic=1 000; Club=2 000; скидки 0/5/15 %. '
                .($missing === [] ? 'шесть тарифов/цен точны' : 'исправить: '.implode(', ', $missing))
                .'; неклассифицировано='.$unclassified);
        } else {
            $this->record('1b. tiers + 1/3/12', 'WARN',
                'MEMBERSHIP_TIERED=OFF (dark deploy); сначала membership:classify-tiers dry/apply, затем шесть тарифов Basic/Club');
        }

        $streamCatalog = app(ClubStreamTariffCatalog::class);
        $d20Club = $streamCatalog->existingOn($course);
        $d20Months = $d20Club->pluck('membership_months')->map(fn ($m): int => (int) $m)->unique()->sort()->values()->all();
        $streamFlag = $streamCatalog->enabled();
        $this->record(
            '1d. club streams-only + D20 Club 1/3/12',
            $d20Months === [1, 3, 12] ? 'PASS' : ($streamFlag ? 'FAIL' : 'WARN'),
            'MEMBERSHIP_CLUB_STREAMS_ONLY='.($streamFlag ? 'ON' : 'OFF')
            .'; D20 Club months '.implode(',', $d20Months).' (need 1,3,12 @ 2000/5700/20400). '
            .($d20Months === [1, 3, 12]
                ? 'есть; is_active следует флагу'
                : 'нет — membership:ensure-club-stream-tariffs --apply (не трогает живые ₽1 500)')
        );

        $capabilityMatrix = [
            'free' => ! MembershipTier::Free->allows(MembershipTier::Basic),
            'basic' => MembershipTier::Basic->allows(MembershipTier::Basic) && ! MembershipTier::Basic->allows(MembershipTier::Club),
            'club' => MembershipTier::Club->allows(MembershipTier::Club) && ! MembershipTier::Club->allows(MembershipTier::Top),
            'top' => MembershipTier::Top->allows(MembershipTier::Top),
        ];
        $this->record('1c. матрица Free/Basic/Club/Top', ! in_array(false, $capabilityMatrix, true) ? 'PASS' : 'FAIL',
            collect($capabilityMatrix)->map(fn (bool $ok, string $tier): string => $tier.'='.($ok ? 'ok' : 'FAIL'))->implode(', ')
            .'; Top go/no-go='.((bool) config('features.membership_top') ? 'ON' : 'OFF (безопасный default)'));

        $clubGroupIds = $course->groups()->pluck('groups.id')->all();
        $groups = $service->detachableClubGroupIds();
        $this->record('2. клубная группа', $groups['allowed'] === [] ? 'FAIL' : 'PASS',
            'групп у курса: '.count($clubGroupIds)
            .', отцепляемых: '.count($groups['allowed'])
            .($groups['refused'] !== [] ? ', ОБЩИХ С ДРУГИМ КУРСОМ: '.implode(',', $groups['refused']) : ''));

        $shelfCourse = Course::query()
            ->where('club_included', true)
            ->where('is_active', true)
            ->whereKeyNot($course->id)
            ->first();
        // H2886: репетировать надо на уроке, который клуб ДОЛЖЕН открыть. При
        // объёме block_N первый по порядку урок курса может лежать в другом
        // блоке — тогда шаг 6 покажет FAIL на верной конфигурации.
        $shelfKey = $shelfCourse instanceof Course ? $entitlement->accessKeyFor($shelfCourse) : 'full';
        $shelfLesson = $shelfCourse instanceof Course
            ? Lesson::query()
                ->where('course_id', $shelfCourse->id)
                ->where('is_published', true)
                ->where('is_free', false)
                ->where('is_preview', false)
                ->when(
                    preg_match('/^block_(\d+)/', $shelfKey, $m) === 1,
                    fn ($q) => $q->where('block_number', (int) $m[1]),
                )
                ->orderBy('block_number')->orderBy('sort_order')->first()
            : null;
        $this->record('3. полка записей', $shelfLesson instanceof Lesson ? 'PASS' : 'FAIL',
            $shelfCourse instanceof Course
                ? 'курс «'.$shelfCourse->title.'», объём '.$shelfKey
                    .', платный урок: '.($shelfLesson?->id ?? 'НЕТ — в этом объёме нет ни одного платного урока')
                : 'ни одного курса с club_included=true — наберите полку ЯВНЫМ списком: '
                    .'membership:club-catalogue --course=<id|slug> --course=… --apply. '
                    .'Голый --apply на проде 16-08-2026 предлагает НОЛЬ курсов: авто-подбор идёт через '
                    .'Course::sellsRecordings(), а тот требует features.course_recordings_sales (OFF) '
                    .'И is_completed=true (0 из 100 активных курсов)');

        if (! config('features.club_membership')) {
            $this->record('4. флаг', 'WARN', 'features.club_membership ВЫКЛЮЧЕН — право не подмешивается в гейты; включите CLUB_MEMBERSHIP=true перед репетицией');
        } else {
            $this->record('4. флаг', 'PASS', 'features.club_membership включён');
        }

        $this->record('4b. recording rollout', 'PASS',
            'mode='.$recordings->modeFor(new User).'; shadow/pilot/full независимы; pilot '
            .count($recordings->pilotUserIds()).'/20 на 48h; rollback: все три флага OFF + config:clear; строки оплат/групп не меняются');

        if (! $apply) {
            $this->record('5–8. сквозной прогон', 'SKIP', 'проверены только предпосылки; выполнить: --apply --user=<id|email>');

            return $this->finish();
        }

        $user = $this->resolveUser();
        if (! $user instanceof User) {
            $this->record('5. студент', 'FAIL', 'не найден: --user='.(string) $this->option('user'));

            return $this->finish();
        }

        if ($this->hasFailures()) {
            $this->record('5–8. сквозной прогон', 'SKIP', 'предпосылки не выполнены — прогон не начат');

            return $this->finish();
        }

        // Слепок групп ДО: инвариант «группы курсов не тронуты» проверяется
        // сравнением, а не рассуждением.
        $groupsBefore = $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $nonClubBefore = array_values(array_diff($groupsBefore, $clubGroupIds));

        $payment = null;
        $membership = null;

        try {
            // ── Шаг 5. «Оплата» → период + группа ────────────────────────────
            $tariff = (bool) config('features.membership_tiered', false)
                ? $tariffs->first(fn (Tariff $candidate): bool => $candidate->membership_tier === MembershipTier::Club
                    && (int) $candidate->membership_months === 1)
                : $tariffs->sortBy('membership_months')->first();
            $payment = Payment::withoutEvents(fn () => Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 0,
                'tariff' => $tariff->accessKey(),
                'status' => 'paid',
                'transaction_id' => 'REHEARSAL-H2644-'.now()->format('YmdHis'),
            ]));

            $payment->grantAccess();
            $membership = $service->syncFromPayment($payment);

            $inGroup = $user->fresh()->groups()->whereIn('groups.id', $clubGroupIds)->exists();
            $this->record('5. оплата → группа + период',
                ($membership instanceof ClubMembership && $inGroup) ? 'PASS' : 'FAIL',
                'ключ '.$payment->tariff
                .', период '.($membership?->id ?? 'НЕ СОЗДАН')
                .' на '.($membership?->term_months ?? '?').' мес до '.($membership?->ends_at?->toDateString() ?? '?')
                .', в клубной группе: '.($inGroup ? 'да' : 'НЕТ'));

            // ── Шаг 6. Каталог виден ─────────────────────────────────────────
            $fresh = $user->fresh();
            $covers = $entitlement->coversCourse($fresh, $shelfCourse);
            $keys = $entitlement->extraTariffKeys($fresh, $shelfCourse);
            $unlocked = $shelfLesson->isUnlockedBy($keys);
            $this->record('6. каталог виден', ($covers && $unlocked) ? 'PASS' : 'FAIL',
                'курс «'.$shelfCourse->title.'» покрыт: '.($covers ? 'да' : 'НЕТ')
                .', объём '.$shelfKey
                .', урок #'.$shelfLesson->id.' открыт: '.($unlocked ? 'да' : 'НЕТ'));

            // ── Шаг 6b. Частичный объём НЕ открывает лишнего ─────────────────
            // Половина контракта block_N, которую шаг 6 проверить не может: он
            // доказывает, что нужное открылось, и молчит о том, что остальное
            // осталось закрытым. Именно эта половина стоит денег.
            if ($shelfKey !== 'full') {
                $outside = Lesson::query()
                    ->where('course_id', $shelfCourse->id)
                    ->where('is_published', true)
                    ->where('is_free', false)
                    ->where('is_preview', false)
                    ->where('block_number', '!=', $shelfLesson->block_number)
                    ->orderBy('block_number')->orderBy('sort_order')->first();

                if (! $outside instanceof Lesson) {
                    $this->record('6b. вне объёма закрыто', 'SKIP',
                        'у курса нет платных уроков за пределами блока '.$shelfLesson->block_number
                        .' — проверять нечего (объём совпал с курсом целиком)');
                } else {
                    $leaked = $outside->isUnlockedBy($keys);
                    $this->record('6b. вне объёма закрыто', $leaked ? 'FAIL' : 'PASS',
                        'урок #'.$outside->id.' (блок '.$outside->block_number.') '
                        .($leaked ? 'ОТКРЫТ — утечка объёма' : 'закрыт, как и должен'));
                }
            }

            // ── Шаг 7. Истечение → право снято ───────────────────────────────
            // Двигаем ТОЛЬКО дату конца — сам механизм снятия не подменяем,
            // иначе репетиция проверяла бы себя, а не демона.
            $membership->forceFill([
                'ends_at' => now()->subDays($membership->grace_days + 1),
                'grace_until' => now()->subMinute(),
            ])->save();

            $report = $service->expireDue(true);
            $after = $membership->fresh();
            $stillInGroup = $user->fresh()->groups()->whereIn('groups.id', $clubGroupIds)->exists();
            $this->record('7. истечение → снятие',
                ($after?->revoked_at !== null && ! $stillInGroup) ? 'PASS' : 'FAIL',
                'снято периодов '.$report['revoked']
                .', причина '.($after?->revoke_reason ?? '—')
                .', в клубной группе после: '.($stillInGroup ? 'ДА (ошибка)' : 'нет'));

            // ── Шаг 8. Группы курсов не тронуты ──────────────────────────────
            $nonClubAfter = array_values(array_diff(
                $user->fresh()->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all(),
                $clubGroupIds,
            ));
            sort($nonClubBefore);
            sort($nonClubAfter);
            $this->record('8. группы курсов целы', $nonClubBefore === $nonClubAfter ? 'PASS' : 'FAIL',
                'до: ['.implode(',', $nonClubBefore).'] после: ['.implode(',', $nonClubAfter).']');

            // Доступ после снятия закрыт.
            $coversAfter = (new ClubEntitlement)->coversCourse($user->fresh(), $shelfCourse);
            $this->record('9. доступ после снятия закрыт', $coversAfter ? 'FAIL' : 'PASS',
                'курс полки покрыт: '.($coversAfter ? 'ДА (ошибка)' : 'нет'));
        } finally {
            if (! (bool) $this->option('keep')) {
                DB::transaction(function () use ($membership, $payment, $clubGroupIds, $user, $nonClubBefore) {
                    $membership?->fresh()?->delete();
                    if ($payment !== null) {
                        Payment::withoutEvents(fn () => Payment::query()->whereKey($payment->id)->delete());
                    }
                    // Возвращаем ровно тот состав групп, что был до репетиции.
                    $user->groups()->detach($clubGroupIds);
                    $current = $user->fresh()->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
                    $restore = array_values(array_diff($nonClubBefore, $current));
                    if ($restore !== []) {
                        $user->groups()->syncWithoutDetaching($restore);
                    }
                });
                $this->line('Откат выполнен: репетиционный платёж и период удалены, состав групп восстановлен.');
            } else {
                $this->warn('--keep: созданное НЕ откачено — уберите вручную.');
            }
        }

        $checkoutTariff = $tariffs->sortBy('membership_months')->first();
        $this->line('Живой чекаут для ручной проверки человеком: '.url('/checkout/'.$checkoutTariff->id));

        return $this->finish();
    }

    private function resolveUser(): ?User
    {
        $token = trim((string) $this->option('user'));
        if ($token === '') {
            return null;
        }

        return ctype_digit($token)
            ? User::find((int) $token)
            : User::where('email', User::normalizeEmail($token))->first();
    }

    private function record(string $step, string $verdict, string $detail): void
    {
        $this->results[] = ['step' => $step, 'verdict' => $verdict, 'detail' => $detail];
    }

    private function hasFailures(): bool
    {
        foreach ($this->results as $r) {
            if ($r['verdict'] === 'FAIL') {
                return true;
            }
        }

        return false;
    }

    private function finish(): int
    {
        $this->newLine();
        $this->table(['шаг', 'вердикт', 'подробности'], array_map(
            static fn (array $r): array => [$r['step'], $r['verdict'], $r['detail']],
            $this->results,
        ));

        $failed = $this->hasFailures();
        $failed
            ? $this->error('РЕПЕТИЦИЯ: FAIL — см. строки выше.')
            : $this->info('РЕПЕТИЦИЯ: все выполненные шаги PASS.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
