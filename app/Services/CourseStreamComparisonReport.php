<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\CourseFamilyMatcher;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H3083 — потоки одного курса бок о бок: ученики по блокам и удержание, деньги,
 * пересечение потоков, поимённый отток, начислено/выплачено/остаток.
 *
 * Правила, которые здесь НЕ переизобретаются (§1 ARCHITECTURE):
 *
 *   - «оплатил блок N» — только через `Payment::coversBlockHalf`; второй копии
 *     правила доступа в системе быть не должно;
 *   - не-выручные тарифы — `TeacherSalaryService::NON_REVENUE_TARIFFS`, а не
 *     свой список;
 *   - люди по блокам — вызовом `CourseBlockParticipantsReport::forCourse`
 *     на каждый курс семьи;
 *   - начисление ЗП и остаток — `TeacherPayoutReconciliation` (который, в свою
 *     очередь, только читает `TeacherSalaryService`).
 *
 * Страница не считает ничего: вся арифметика здесь, чтобы её можно было
 * прогнать тестом и консольной командой сверки.
 *
 * ДВА СЧЁТА ПО БЛОКУ, и это намеренно. `buyers` — сколько человек купили
 * ИМЕННО этот блок (совпадает с выручкой блока и с замером §1 PLAN);
 * `access` — сколько человек имеют к блоку доступ, включая купивших курс
 * целиком. На курсе 332 это 44 против 46: двое купили «весь курс» постфактум.
 * Показывать одно без другого значит либо занизить охват, либо разойтись с
 * деньгами.
 */
class CourseStreamComparisonReport
{
    public function __construct(
        private readonly CourseBlockParticipantsReport $participants,
        private readonly CourseFamilyMatcher $matcher,
        private readonly TeacherPayoutReconciliation $reconciliation,
    ) {}

    /**
     * Список семей, в которых есть хотя бы один курс, для селектора на странице.
     *
     * @return array<string, string> слаг семьи => подпись
     */
    public function families(): array
    {
        $rows = Course::query()
            ->whereNotNull('course_family')
            ->where('course_family', '!=', '')
            ->orderBy('id')
            ->get(['id', 'title', 'course_family']);

        $out = [];
        foreach ($rows->groupBy('course_family') as $family => $courses) {
            $out[(string) $family] = $this->familyTitle($courses).' ('.$courses->count().')';
        }

        ksort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>|null null = в семье нет ни одного курса
     */
    public function forFamily(string $family): ?array
    {
        $courses = Course::query()->inFamily($family)->get();

        if ($courses->isEmpty()) {
            return null;
        }

        $streams = [];
        /** @var array<int, array<int, true>> $payersByCourse */
        $payersByCourse = [];

        foreach ($courses as $course) {
            [$stream, $payers] = $this->stream($course);
            $streams[] = $stream;
            $payersByCourse[(int) $course->id] = $payers;
        }

        // Порядок колонок: по номеру потока, потом по дате первого платежа.
        usort($streams, fn (array $a, array $b): int => $a['sort_key'] <=> $b['sort_key']);

        $salary = $this->reconciliation->forFamily($courses);
        foreach ($streams as &$stream) {
            $stream['accrued'] = $salary['accrued_by_course'][$stream['course_id']] ?? 0.0;
        }
        unset($stream);

        return [
            'family' => $family,
            'family_title' => $this->familyTitle($courses),
            'streams' => $streams,
            'crossover' => $this->crossover($streams, $payersByCourse),
            'attendance' => $this->attendance($payersByCourse),
            'salary' => $salary,
            'totals' => [
                'revenue' => Money::round(array_sum(array_column($streams, 'revenue'))),
                'payers' => count($this->unionOf($payersByCourse)),
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, true>}
     */
    private function stream(Course $course): array
    {
        $report = $this->participants->forCourse($course);

        $payments = Payment::query()
            ->where('course_id', $course->id)
            ->paid()
            ->where(fn ($q) => $q->whereNull('is_conditional')->orWhere('is_conditional', false))
            ->whereNotIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
            ->get(['id', 'user_id', 'tariff', 'amount', 'discount_amount', 'start_block', 'end_block', 'created_at']);

        $payers = [];
        $revenue = 0.0;
        $discount = 0.0;
        $firstPaymentAt = null;

        foreach ($payments as $p) {
            $revenue += (float) $p->amount;
            $discount += (float) ($p->discount_amount ?? 0);
            if ($p->user_id) {
                $payers[(int) $p->user_id] = true;
            }
            if ($firstPaymentAt === null || ($p->created_at && $p->created_at->lt($firstPaymentAt))) {
                $firstPaymentAt = $p->created_at;
            }
        }

        // Номера блоков: у курса-записи блоков нет вовсе, а платежи с ключами
        // block_N есть — иначе колонка «в записи» осталась бы пустой.
        $blockNumbers = $report['block_numbers'];
        if ($blockNumbers === []) {
            $blockNumbers = $this->blockNumbersFromPayments($payments);
        }

        $blocks = [];
        $accessByBlock = [];
        foreach ($blockNumbers as $n) {
            $buyers = [];
            $access = [];
            $blockRevenue = 0.0;

            foreach ($payments as $p) {
                if (! $p->user_id) {
                    continue;
                }
                if (! $p->coversBlockHalf($n, 1) && ! $p->coversBlockHalf($n, 2)) {
                    continue;
                }

                $access[(int) $p->user_id] = true;

                // «Купил именно этот блок» — тариф блока или диапазон,
                // но не `full`: тот покрывает все блоки сразу и своей
                // выручки на блок не имеет.
                if ($p->tariff !== 'full') {
                    $buyers[(int) $p->user_id] = true;
                    $blockRevenue += (float) $p->amount;
                }
            }

            $existing = collect($report['blocks'])->firstWhere('number', $n);

            $blocks[] = [
                'number' => $n,
                'title' => $existing['title'] ?? null,
                'starts_at' => $existing['starts_at'] ?? null,
                'buyers' => count($buyers),
                'access' => count($access),
                'revenue' => Money::round($blockRevenue),
            ];
            $accessByBlock[$n] = $access;
        }

        // Матрица «студент × блок» этого потока — её потребляет выгрузка, чтобы
        // не восстанавливать состав по спискам оттока (восстановление дало бы
        // только выбывших, а строка нужна на каждого плательщика).
        $students = [];
        foreach ($this->names(array_keys($payers)) as $user) {
            $row = ['id' => $user['id'], 'name' => $user['name'], 'blocks' => []];
            foreach ($accessByBlock as $n => $access) {
                $row['blocks'][$n] = isset($access[$user['id']]);
            }
            $students[] = $row;
        }

        $blocksCount = count($report['block_numbers']);
        $tariffsCount = $course->tariffs()->where('is_active', true)->count();
        [$ordinal, $sortKey] = $this->matcher->ordinalFor((string) $course->title, $firstPaymentAt);

        $first = $blocks[0]['buyers'] ?? 0;
        $last = $blocks !== [] ? $blocks[count($blocks) - 1]['buyers'] : 0;

        return [
            [
                'course_id' => (int) $course->id,
                'title' => (string) $course->title,
                'slug' => (string) $course->slug,
                'role' => $this->matcher->streamRole($blocksCount, $tariffsCount, $payments->count()),
                'ordinal' => $ordinal,
                'sort_key' => $sortKey,
                'is_active' => (bool) $course->is_active,
                'teacher_id' => $course->teacher_id ? (int) $course->teacher_id : null,
                'salary_scheme' => $course->salary_type,
                'salary_value' => $course->salary_value !== null ? (float) $course->salary_value : null,
                'participants_total' => $report['participants_total'],
                'payers' => count($payers),
                'revenue' => Money::round($revenue),
                'discount_total' => Money::round($discount),
                'avg_check' => $payers === [] ? 0.0 : Money::round($revenue / count($payers)),
                'blocks' => $blocks,
                'students' => $students,
                'retention_first_to_last' => $first > 0 ? (int) round($last / $first * 100) : null,
                'dropped_between_blocks' => $this->droppedBetweenBlocks($accessByBlock),
                'accrued' => 0.0, // заполняется из сверки в forFamily()
            ],
            $payers,
        ];
    }

    /**
     * Номера блоков, выведенные из тарифов платежей — для курса без блоков
     * (курс-запись 424: доступ выдан платежами block_1…block_4).
     *
     * @param  Collection<int, Payment>  $payments
     * @return list<int>
     */
    private function blockNumbersFromPayments(Collection $payments): array
    {
        $numbers = [];

        foreach ($payments as $p) {
            if (preg_match('/^block_(\d+)(?:_h[12])?$/', (string) $p->tariff, $m)) {
                $numbers[(int) $m[1]] = true;
            }
            $start = (int) $p->start_block;
            if ($start > 0) {
                $end = (int) $p->end_block ?: $start;
                for ($n = $start; $n <= $end; $n++) {
                    $numbers[$n] = true;
                }
            }
        }

        $out = array_keys($numbers);
        sort($out);

        return $out;
    }

    /**
     * Поимённый отток блок → блок: у кого был доступ к блоку N и не стало к N+1.
     *
     * @param  array<int, array<int, true>>  $accessByBlock
     * @return list<array{block_from:int, block_to:int, count:int, users:list<array{id:int, name:string}>}>
     */
    private function droppedBetweenBlocks(array $accessByBlock): array
    {
        $numbers = array_keys($accessByBlock);
        sort($numbers);
        $out = [];

        for ($i = 0; $i < count($numbers) - 1; $i++) {
            $from = $numbers[$i];
            $to = $numbers[$i + 1];
            $gone = array_diff_key($accessByBlock[$from], $accessByBlock[$to]);
            if ($gone === []) {
                continue;
            }

            $out[] = [
                'block_from' => $from,
                'block_to' => $to,
                'count' => count($gone),
                'users' => $this->names(array_keys($gone)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @param  array<int, array<int, true>>  $payersByCourse
     * @return array<string, mixed>
     */
    private function crossover(array $streams, array $payersByCourse): array
    {
        $pairs = [];
        $count = count($streams);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $streams[$i];
                $b = $streams[$j];
                $shared = array_intersect_key(
                    $payersByCourse[$a['course_id']] ?? [],
                    $payersByCourse[$b['course_id']] ?? [],
                );

                $pairs[] = [
                    'from_course_id' => $a['course_id'],
                    'from_title' => $a['title'],
                    'to_course_id' => $b['course_id'],
                    'to_title' => $b['title'],
                    'count' => count($shared),
                    'users' => $this->names(array_keys($shared)),
                ];
            }
        }

        // «Купил запись, но живым потоком не шёл» — когорта, ради которой
        // вопрос про доплату вообще возник (§1 PLAN).
        $recordingOnly = [];
        foreach ($streams as $stream) {
            if ($stream['role'] !== CourseFamilyMatcher::ROLE_RECORDING) {
                continue;
            }

            $buyers = $payersByCourse[$stream['course_id']] ?? [];
            $live = [];
            foreach ($streams as $other) {
                if ($other['course_id'] === $stream['course_id'] || $other['role'] === CourseFamilyMatcher::ROLE_RECORDING) {
                    continue;
                }
                $live += $payersByCourse[$other['course_id']] ?? [];
            }

            $recordingOnly[] = [
                'course_id' => $stream['course_id'],
                'title' => $stream['title'],
                'buyers' => count($buyers),
                'also_live' => count(array_intersect_key($buyers, $live)),
                'only_recording' => count(array_diff_key($buyers, $live)),
                'users' => $this->names(array_keys(array_diff_key($buyers, $live))),
            ];
        }

        return ['pairs' => $pairs, 'recording' => $recordingOnly];
    }

    /**
     * Плательщики, узнанные по связке «экранное имя Zoom → пользователь»
     * (H3761). Учитываются только те, у кого есть хотя бы одна строка
     * посещаемости с этим именем на занятии того же курса — связка сама по
     * себе не означает, что человек приходил.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function attendeesByNameLink(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('webinar_participant_links')) {
            return [];
        }

        return DB::table('webinar_participant_links as l')
            ->join('webinar_attendances as wa', function ($join): void {
                $join->on('wa.name', '=', 'l.zoom_name');
            })
            ->join('schedules as s', function ($join): void {
                $join->on('s.id', '=', 'wa.schedule_id')->on('s.course_id', '=', 'l.course_id');
            })
            ->whereIn('l.user_id', $ids)
            ->distinct()
            ->pluck('l.user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Покрытие данными о посещаемости. Отдаётся ВСЕГДА, даже когда покрытие
     * нулевое: пустая колонка без этой плашки читается как «никто не ходил»,
     * а это была бы ложь в отчёте бухгалтера (§7 ARCHITECTURE).
     *
     * @param  array<int, array<int, true>>  $payersByCourse
     * @return array<string, mixed>
     */
    private function attendance(array $payersByCourse): array
    {
        $all = $this->unionOf($payersByCourse);
        $ids = array_keys($all);
        $total = count($ids);

        if ($total === 0) {
            return [
                'total_users' => 0, 'covered_users' => 0, 'coverage_ratio' => 0.0,
                'lesson_view_users' => 0, 'webinar_users' => 0, 'bought_all_never_watched' => [],
            ];
        }

        $viewers = DB::table('lesson_views')->whereIn('user_id', $ids)->distinct()->pluck('user_id')
            ->map(fn ($id): int => (int) $id)->all();
        $attendees = array_values(array_unique(array_merge(
            DB::table('webinar_attendances')->whereIn('user_id', $ids)->distinct()->pluck('user_id')
                ->map(fn ($id): int => (int) $id)->all(),
            // H3761: у 96 % строк посещаемости `user_id` пуст — Zoom отдаёт почту
            // только для залогиненных в тот же аккаунт. Кто это был, известно
            // лишь по экранному имени, поэтому вторая половина ответа приходит
            // из связок `webinar_participant_links`. Без неё плашка показывала
            // ноль посещавших при сотнях собранных строк.
            $this->attendeesByNameLink($ids),
        )));

        $covered = array_unique(array_merge($viewers, $attendees));

        // «Купил всё, но не смотрел»: плательщик без единого просмотра и без
        // единой отметки посещаемости. При нынешнем покрытии список почти
        // совпадёт со списком плательщиков — на то и плашка.
        $neverWatched = array_values(array_diff($ids, $covered));

        return [
            'total_users' => $total,
            'covered_users' => count($covered),
            'coverage_ratio' => $total > 0 ? round(count($covered) / $total, 4) : 0.0,
            'lesson_view_users' => count($viewers),
            'webinar_users' => count($attendees),
            'bought_all_never_watched' => $this->names($neverWatched),
        ];
    }

    /**
     * @param  array<int, array<int, true>>  $payersByCourse
     * @return array<int, true>
     */
    private function unionOf(array $payersByCourse): array
    {
        $all = [];
        foreach ($payersByCourse as $payers) {
            $all += $payers;
        }

        return $all;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int, name:string}>
     */
    private function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id): array => ['id' => (int) $id, 'name' => (string) $name])
            ->values()
            ->all();
    }

    /**
     * Подпись семьи — общее начало названий её курсов, иначе название первого.
     *
     * @param  Collection<int, Course>  $courses
     */
    private function familyTitle(Collection $courses): string
    {
        $titles = $courses->pluck('title')->map(fn ($t): string => (string) $t)->all();
        if ($titles === []) {
            return '';
        }

        $common = array_shift($titles);
        foreach ($titles as $title) {
            $len = min(mb_strlen($common), mb_strlen($title));
            $i = 0;
            while ($i < $len && mb_substr($common, $i, 1) === mb_substr($title, $i, 1)) {
                $i++;
            }
            $common = mb_substr($common, 0, $i);
        }

        $common = trim((string) preg_replace('/[\s(,\-–—]+$/u', '', $common));

        return $common !== '' ? $common : (string) $courses->first()->title;
    }
}
