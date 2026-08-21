<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\TochkaBalanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H3280 — ISO-week due calendar for teacher payouts. READ ONLY.
 * Never inserts teacher_payouts or payments. PayPal has no balance API.
 */
final class TeacherWeeklyPayoutCalendarService
{
    public const TRIGGER_ANNIVERSARY = 'last_month_anniversary';

    public const TRIGGER_BLOCK4 = 'block_4_end';

    public const COVER_OPEN_BANK = 'open_the_bank';

    public function __construct(
        private readonly TeacherSalaryService $salary,
        private readonly TochkaBalanceService $tochka,
    ) {}

    /**
     * @return array{
     *   year: int,
     *   weeks: list<array<string, mixed>>,
     *   tochka: array<string, mixed>,
     *   paypal_cover: string,
     *   money_tables_moved: bool
     * }
     */
    public function grid(int $year): array
    {
        $payoutsBefore = TeacherPayout::query()->count();
        $paymentsBefore = Payment::query()->count();

        $teachers = Teacher::query()->orderBy('id')->get();
        $summaries = $this->salary->summaryForAll();
        $lastPayout = $this->lastPayoutByTeacher();
        $lastRaskhod = $this->lastRaskhodByTeacher();
        $block4 = $this->block4EndsByTeacher($year, $teachers);

        $weekBuckets = [];
        $weeksInYear = Carbon::create($year, 12, 28)->isoWeek;

        foreach ($teachers as $teacher) {
            $tid = (int) $teacher->id;
            $payoutAt = $lastPayout[$tid] ?? null;
            $raskhodAt = $lastRaskhod[$tid] ?? null;
            $lastCash = $this->maxDate($payoutAt, $raskhodAt);
            $source = $this->lastSource($payoutAt, $raskhodAt);
            $preliminary = $source === 'raskhod';

            $currency = $teacher->payout_currency ?: 'RUB';
            $lane = strtoupper((string) $currency) === 'EUR' ? 'EUR' : 'RUB';
            $balance = (float) ($summaries[$tid]['balance'] ?? 0);

            $rowBase = [
                'teacher_id' => $tid,
                'name' => (string) $teacher->name,
                'lane' => $lane,
                'payout_currency' => $teacher->payout_currency,
                'balance' => $balance,
                'last_teacher_payouts_at' => $payoutAt?->toDateString(),
                'last_raskhod_at' => $raskhodAt?->toDateString(),
                'last_cash_source' => $source,
                'preliminary' => $preliminary,
            ];

            if ($lastCash !== null) {
                for ($m = 1; $m <= 18; $m++) {
                    $due = $lastCash->copy()->addMonthsNoOverflow($m)->startOfDay();
                    if ((int) $due->isoWeekYear !== $year) {
                        if ($due->year > $year) {
                            break;
                        }

                        continue;
                    }
                    $this->pushDue($weekBuckets, $due, $rowBase, self::TRIGGER_ANNIVERSARY);
                }
            }

            foreach ($block4[$tid] ?? [] as $end) {
                if ((int) $end->isoWeekYear !== $year) {
                    continue;
                }
                $this->pushDue($weekBuckets, $end, $rowBase, self::TRIGGER_BLOCK4);
            }
        }

        $tochka = $this->tochka->snapshot();
        $weeks = [];
        for ($w = 1; $w <= $weeksInYear; $w++) {
            $start = Carbon::now()->setISODate($year, $w)->startOfWeek(Carbon::MONDAY);
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $dues = $weekBuckets[$w] ?? [];
            $rubNeed = 0.0;
            $eurDue = false;
            foreach ($dues as $d) {
                if ($d['lane'] === 'EUR') {
                    $eurDue = true;
                } else {
                    $rubNeed += max(0.0, (float) $d['balance']);
                }
            }
            $tochkaCover = null;
            if ($rubNeed <= 0) {
                $tochkaCover = 'n/a';
            } elseif (! $tochka['ok']) {
                $tochkaCover = 'no_bank';
            } else {
                $tochkaCover = ((float) $tochka['closing_total']) + 0.0001 >= $rubNeed ? 'enough' : 'short';
            }

            $weeks[] = [
                'iso_week' => $w,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'due' => array_values($dues),
                'rub_need' => round($rubNeed, 2),
                'tochka_cover' => $tochkaCover,
                'paypal_cover' => $eurDue ? self::COVER_OPEN_BANK : 'n/a',
            ];
        }

        $moved = TeacherPayout::query()->count() !== $payoutsBefore
            || Payment::query()->count() !== $paymentsBefore;

        return [
            'year' => $year,
            'weeks' => $weeks,
            'tochka' => $tochka,
            'paypal_cover' => self::COVER_OPEN_BANK,
            'paypal_note' => 'PayPal has no balance API in this app — open the bank.',
            'money_tables_moved' => $moved,
        ];
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $weekBuckets
     * @param  array<string, mixed>  $rowBase
     */
    private function pushDue(array &$weekBuckets, Carbon $on, array $rowBase, string $trigger): void
    {
        $w = (int) $on->isoWeek;
        $tid = (int) $rowBase['teacher_id'];
        $key = $tid.'|'.$trigger;
        $weekBuckets[$w] ??= [];
        if (isset($weekBuckets[$w][$key])) {
            $weekBuckets[$w][$key]['triggers'][] = $trigger;

            return;
        }
        $weekBuckets[$w][$key] = $rowBase + [
            'due_on' => $on->toDateString(),
            'trigger' => $trigger,
            'triggers' => [$trigger],
        ];
    }

    /** @return array<int, Carbon> */
    private function lastPayoutByTeacher(): array
    {
        $rows = TeacherPayout::query()
            ->selectRaw('teacher_id, MAX(paid_at) AS last_paid_at')
            ->groupBy('teacher_id')
            ->pluck('last_paid_at', 'teacher_id');
        $out = [];
        foreach ($rows as $id => $raw) {
            if ($raw) {
                $out[(int) $id] = Carbon::parse($raw)->startOfDay();
            }
        }

        return $out;
    }

    /** @return array<int, Carbon> */
    private function lastRaskhodByTeacher(): array
    {
        $userIds = User::query()->whereNotNull('teacher_id')->pluck('teacher_id', 'id');
        if ($userIds->isEmpty()) {
            return [];
        }
        $payments = Payment::query()
            ->where('tariff', 'Расход')
            ->where('status', 'paid')
            ->whereIn('user_id', $userIds->keys())
            ->get(['user_id', 'created_at']);
        $out = [];
        foreach ($payments as $p) {
            $tid = (int) ($userIds[$p->user_id] ?? 0);
            if ($tid === 0 || $p->created_at === null) {
                continue;
            }
            $d = Carbon::parse($p->created_at)->startOfDay();
            if (! isset($out[$tid]) || $d->gt($out[$tid])) {
                $out[$tid] = $d;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, Teacher>  $teachers
     * @return array<int, list<Carbon>>
     */
    private function block4EndsByTeacher(int $year, $teachers): array
    {
        $byCourse = [];
        foreach ($teachers as $t) {
            foreach ($t->allTaughtCourses() as $course) {
                $byCourse[(int) $course->id][] = (int) $t->id;
            }
        }
        if ($byCourse === []) {
            return [];
        }
        $blocks = CourseBlock::query()
            ->where('number', 4)
            ->whereNotNull('ends_at')
            ->whereIn('course_id', array_keys($byCourse))
            ->get(['course_id', 'ends_at']);
        $out = [];
        foreach ($blocks as $b) {
            $end = Carbon::parse($b->ends_at)->startOfDay();
            if ((int) $end->isoWeekYear !== $year && $end->year !== $year) {
                continue;
            }
            foreach ($byCourse[(int) $b->course_id] ?? [] as $tid) {
                $out[$tid][] = $end;
            }
        }

        return $out;
    }

    private function maxDate(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a->gte($b) ? $a : $b;
    }

    private function lastSource(?Carbon $payout, ?Carbon $raskhod): string
    {
        if ($payout === null && $raskhod === null) {
            return 'none';
        }
        if ($payout === null) {
            return 'raskhod';
        }
        if ($raskhod === null) {
            return 'teacher_payouts';
        }

        return $raskhod->gt($payout) ? 'raskhod' : 'teacher_payouts';
    }
}
