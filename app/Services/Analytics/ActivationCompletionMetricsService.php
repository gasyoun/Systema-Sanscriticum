<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Services\StudentUnitEconomicsService;
use App\Support\Roles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O2 (активация) + C4 (завершаемость) — H3764.
 *
 * Ничего не хранит и ничего не пишет: считает из живой БД по требованию.
 * Каждая цифра возвращается ВМЕСТЕ со своим знаменателем (`*_denominator`,
 * `*_denominator_hint`) — это условие приёмки H3764 и урок измерительного
 * хребта H2378: процент без названного знаменателя не факт, а украшение.
 *
 * Якорь активации НЕ свой: берётся из
 * {@see StudentUnitEconomicsService::acquisitionAnchors()} — тот же момент
 * «первая доступо-открывающая покупка», от которого считаются LTV/CAC. Если
 * завести здесь второе определение «привлечённого ученика», две страницы
 * начнут спорить о числе учеников, и обе будут «правы».
 *
 * Пороги (окно когорт, доля уроков для «дошёл до конца», минимальный
 * знаменатель) живут в config/activation_metrics.php, не в вёрстке.
 */
final class ActivationCompletionMetricsService
{
    /** Статусы домашней работы, которые считаются СДАННОЙ (draft — не сдана). */
    private const SUBMITTED_HOMEWORK_STATUSES = ['submitted', 'needs_revision', 'accepted'];

    public function __construct(
        private readonly StudentUnitEconomicsService $unitEconomics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        return [
            'as_of' => $asOf->toDateTimeString(),
            'config_source' => 'config/activation_metrics.php',
            'min_denominator' => $this->minDenominator(),
            'activation' => $this->activation($asOf),
            'completion' => $this->completion(),
        ];
    }

    // ── O2. Активация ───────────────────────────────────────────────────────

    /**
     * Воронка активации по месячным когортам: оплатил → вошёл → открыл урок →
     * сдал домашнюю. Каждый шаг — доля от ОДНОГО знаменателя (размер когорты),
     * а не от предыдущего шага: так видно, где именно теряются люди.
     *
     * @return array<string, mixed>
     */
    public function activation(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $months = max(1, (int) config('activation_metrics.cohort_months', 12));
        $windowStart = $asOf->copy()->startOfMonth()->subMonths($months - 1);

        // Якорь = первая доступо-открывающая покупка (общий с юнит-экономикой).
        $anchors = $this->unitEconomics->acquisitionAnchors()
            ->filter(fn (array $a) => $a['date']->gte($windowStart) && $a['date']->lte($asOf));

        $studentIds = $this->studentIds($anchors->keys()->all());
        $anchors = $anchors->filter(fn (array $_a, int $userId) => in_array($userId, $studentIds, true));

        if ($anchors->isEmpty()) {
            return [
                'found' => false,
                'window_from' => $windowStart->toDateString(),
                'window_to' => $asOf->toDateString(),
                'denominator_hint' => $this->activationDenominatorHint(),
                'cohorts' => [],
                'total' => null,
            ];
        }

        $userIds = $anchors->keys()->all();
        $loggedIn = $this->everLoggedIn($userIds);
        $firstLessonAt = $this->firstLessonViewAt($userIds);
        $firstHomeworkAt = $this->firstHomeworkAt($userIds);
        $lessonSince = $this->telemetrySince('lesson_views', 'first_opened_at');
        $homeworkSince = $this->telemetrySince('homework_submissions', 'created_at');

        $cohorts = [];
        foreach ($anchors as $userId => $anchor) {
            $key = $anchor['date']->copy()->startOfMonth()->format('Y-m');
            $cohorts[$key] ??= ['month' => $key, 'rows' => []];
            $cohorts[$key]['rows'][] = [
                'user_id' => $userId,
                'anchor' => $anchor['date'],
                'logged_in' => in_array($userId, $loggedIn, true),
                'first_lesson_at' => $firstLessonAt[$userId] ?? null,
                'first_homework_at' => $firstHomeworkAt[$userId] ?? null,
            ];
        }
        ksort($cohorts);

        $shaped = [];
        foreach ($cohorts as $key => $cohort) {
            $shaped[] = $this->shapeCohort($key, $cohort['rows'], $lessonSince, $homeworkSince);
        }

        $allRows = collect($cohorts)->flatMap(fn (array $c) => $c['rows'])->all();

        return [
            'found' => true,
            'window_from' => $windowStart->toDateString(),
            'window_to' => $asOf->toDateString(),
            'window_months' => $months,
            'denominator_hint' => $this->activationDenominatorHint(),
            'lesson_telemetry_since' => $lessonSince?->toDateString(),
            'homework_telemetry_since' => $homeworkSince?->toDateString(),
            'telemetry_hint' => $this->telemetryHint($lessonSince, $homeworkSince),
            'cohorts' => $shaped,
            'total' => $this->shapeCohort('всего', $allRows, $lessonSince, $homeworkSince),
        ];
    }

    /**
     * Шаг воронки измерим только с того дня, когда в системе появилась первая
     * строка соответствующей телеметрии. Когорта старше этого дня даёт не «мало
     * активированных», а «нечем измерить» — самая дорогая ошибка такой страницы.
     */
    private function telemetrySince(string $table, string $column): ?Carbon
    {
        $min = DB::table($table)->min($column);

        return $min === null ? null : Carbon::parse($min);
    }

    private function telemetryHint(?Carbon $lessonSince, ?Carbon $homeworkSince): string
    {
        $l = $lessonSince?->toDateString() ?? '—';
        $h = $homeworkSince?->toDateString() ?? '—';

        return 'Телеметрия уроков ведётся с '.$l.', домашних работ — с '.$h.'.'
            .' У когорт, закончившихся ДО этих дат, соответствующий шаг помечен «нечем измерить»'
            .' и НЕ показывается как 0 %: до включения счётчика мы просто не знали, кто что открыл.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function shapeCohort(
        string $label,
        array $rows,
        ?Carbon $lessonSince = null,
        ?Carbon $homeworkSince = null,
    ): array {
        $denominator = count($rows);
        $loggedIn = 0;
        $openedLesson = 0;
        $submittedHomework = 0;
        $ttflDays = [];

        foreach ($rows as $row) {
            if ($row['logged_in']) {
                $loggedIn++;
            }

            /** @var Carbon $anchor */
            $anchor = $row['anchor'];
            $firstLesson = $row['first_lesson_at'];
            if ($firstLesson instanceof Carbon) {
                $openedLesson++;
                // TTFL считаем только ПОСЛЕ якоря: урок, открытый до первой
                // покупки, — это бесплатная витрина, а не активация оплаты.
                if ($firstLesson->gte($anchor)) {
                    $ttflDays[] = $anchor->diffInDays($firstLesson);
                }
            }

            if ($row['first_homework_at'] instanceof Carbon) {
                $submittedHomework++;
            }
        }

        // Шаг измерим, если счётчик включился НЕ ПОЗЖЕ конца месяца самой старой
        // когорты в строке: иначе её первые недели прошли вслепую и низкий
        // процент означал бы «не считали», а не «не активировались». Для строки
        // «всего» это самая старая когорта окна — агрегат по смешанному окну
        // честно помечается неизмеримым.
        $oldest = collect($rows)->min(fn (array $r) => $r['anchor']);
        $horizon = $oldest instanceof Carbon ? $oldest->copy()->endOfMonth() : null;
        $lessonMeasurable = $lessonSince === null || ($horizon !== null && $lessonSince->lte($horizon));
        $homeworkMeasurable = $homeworkSince === null || ($horizon !== null && $homeworkSince->lte($horizon));

        return [
            'month' => $label,
            'denominator' => $denominator,
            'reliable' => $denominator >= $this->minDenominator(),
            'logged_in' => $loggedIn,
            'logged_in_pct' => $this->pct($loggedIn, $denominator),
            'lesson_measurable' => $lessonMeasurable,
            'homework_measurable' => $homeworkMeasurable,
            'opened_lesson' => $lessonMeasurable ? $openedLesson : null,
            'opened_lesson_pct' => $lessonMeasurable ? $this->pct($openedLesson, $denominator) : null,
            'submitted_homework' => $homeworkMeasurable ? $submittedHomework : null,
            'submitted_homework_pct' => $homeworkMeasurable ? $this->pct($submittedHomework, $denominator) : null,
            'ttfl_median_days' => $lessonMeasurable ? $this->median($ttflDays) : null,
            'ttfl_denominator' => count($ttflDays),
            'ttfl_denominator_hint' => 'медиана считается только по тем, кто открыл урок ПОСЛЕ первой покупки'
                .' ('.count($ttflDays).' из '.$denominator.'); никогда не открывшие в медиану не входят и не заменяются нулём',
        ];
    }

    private function activationDenominatorHint(): string
    {
        return 'знаменатель каждой строки — ученики, чья ПЕРВАЯ доступо-открывающая покупка'
            .' (payments: оплачен, реальный, сумма > 0, не «Расход»/«salary_payout»/deposit/trial)'
            .' пришлась на этот месяц; тот же якорь, что у юнит-экономики ученика.'
            .' Сотрудники ('.implode(', ', array_keys(Roles::all())).') исключены.'
            .' Каждый шаг воронки — доля от ЭТОГО знаменателя, а не от предыдущего шага.';
    }

    // ── C4. Завершаемость ───────────────────────────────────────────────────

    /**
     * Завершаемость по курсу и по потоку (группе). Две метрики рядом:
     * порог по пройденным урокам (config) и выданный сертификат.
     *
     * @return array<string, mixed>
     */
    public function completion(): array
    {
        $ratio = $this->completionRatio();
        $limit = max(0, (int) config('activation_metrics.completion_rows', 20));

        $courses = $this->courseCompletion($ratio);
        $groups = $this->groupCompletion($ratio);

        $courseTotal = count($courses);
        $groupTotal = count($groups);

        if ($limit > 0) {
            $courses = $this->limitKeepingSignal($courses, $limit);
            $groups = $this->limitKeepingSignal($groups, $limit);
        }

        return [
            'found' => $courses !== [] || $groups !== [],
            'lesson_ratio' => $ratio,
            'courses' => $courses,
            'groups' => $groups,
            'courses_total' => $courseTotal,
            'groups_total' => $groupTotal,
            'limit_hint' => 'список отсортирован по размеру знаменателя и обрезан, НО строка с ненулевым'
                .' результатом (дошедшие до порога или выданные сертификаты) не отрезается никогда:'
                .' иначе страница показывала бы стену нулей, спрятав ровно те курсы, где кто-то дошёл'
                .' (прод 01-09-2026: сигнал был у 5 курсов из 116, и 4 из них не попадали в топ-20).',
            'completion_source_hint' => 'пройденные уроки берутся из пивота lesson_user.is_completed'
                .' (туда пишет кнопка «урок пройден»), а НЕ из lesson_views.is_completed:'
                .' второй столбец на проде пуст при 649 строках просмотров и дал бы ровный 0 % по всем курсам.',
            'course_denominator_hint' => 'знаменатель курса — строки course_user (запись на курс),'
                .' без сотрудников. Рядом показано число учеников с оплатой этого курса:'
                .' расхождение видно, а не спрятано.',
            'group_denominator_hint' => 'знаменатель потока — строки group_user (состав группы), без сотрудников.'
                .' Уроки потока — lessons.group_id; у потока без своих уроков завершаемость «нет данных», а не 0 %.',
            'certificate_hint' => 'сертификат выдаёт человек (CertificateService), а не порог:'
                .' это более строгая метрика, чем доля пройденных уроков.',
        ];
    }

    /**
     * Обрезать список до $limit, НИКОГДА не выбрасывая строку с ненулевым
     * результатом. Сортировка по знаменателю разумна (крупные курсы важнее),
     * но на боевых данных 01-09-2026 сигнал был ровно у 5 курсов из 116, и
     * четыре из них не попадали в топ-20 по числу записанных — страница
     * показывала двадцать строк сплошных 0 % и прятала всё, что произошло.
     * Метрика, которая скрывает собственный сигнал, хуже отсутствующей.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function limitKeepingSignal(array $rows, int $limit): array
    {
        $hasSignal = static fn (array $r): bool => (int) ($r['completed'] ?? 0) > 0
            || (int) ($r['certified'] ?? 0) > 0;

        $kept = array_values(array_filter($rows, $hasSignal));
        $rest = array_values(array_filter($rows, static fn (array $r): bool => ! $hasSignal($r)));

        $room = max(0, $limit - count($kept));
        $out = array_merge($kept, array_slice($rest, 0, $room));

        // Порядок исходного списка (по убыванию знаменателя) сохраняем: строки
        // с сигналом не всплывают наверх, они просто не выпадают.
        usort($out, static fn (array $a, array $b) => $b['denominator'] <=> $a['denominator']);

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function courseCompletion(float $ratio): array
    {
        $lessonsPerCourse = Lesson::query()
            ->select('course_id', DB::raw('COUNT(*) as c'))
            ->groupBy('course_id')
            ->pluck('c', 'course_id');

        $enrolled = DB::table('course_user')
            ->join('users', 'users.id', '=', 'course_user.user_id')
            ->where(function ($q) {
                $q->whereNull('users.role')->orWhereNotIn('users.role', array_keys(Roles::all()));
            })
            ->select('course_user.course_id', 'course_user.user_id')
            ->get()
            ->groupBy('course_id');

        $paidPerCourse = $this->paidStudentsPerCourse();
        $completedPerCourse = $this->completedLessonCounts();
        $certifiedPerCourse = $this->certifiedStudentsPerCourse();
        $courseNames = Course::query()->pluck('title', 'id');

        $rows = [];
        foreach ($enrolled as $courseId => $members) {
            $courseId = (int) $courseId;
            $denominator = $members->pluck('user_id')->unique();
            $lessonsTotal = (int) ($lessonsPerCourse[$courseId] ?? 0);
            $needed = $lessonsTotal > 0 ? (int) ceil($lessonsTotal * $ratio) : 0;

            $completed = 0;
            if ($lessonsTotal > 0) {
                foreach ($denominator as $userId) {
                    if ((int) ($completedPerCourse[$courseId][(int) $userId] ?? 0) >= $needed) {
                        $completed++;
                    }
                }
            }

            $certified = collect($certifiedPerCourse[$courseId] ?? [])
                ->intersect($denominator->all())
                ->count();

            $rows[] = [
                'course_id' => $courseId,
                'name' => (string) ($courseNames[$courseId] ?? ('Курс #'.$courseId)),
                'denominator' => $denominator->count(),
                'reliable' => $denominator->count() >= $this->minDenominator(),
                'paid_students' => (int) ($paidPerCourse[$courseId] ?? 0),
                'lessons_total' => $lessonsTotal,
                'lessons_needed' => $needed,
                'completed' => $lessonsTotal > 0 ? $completed : null,
                'completed_pct' => $lessonsTotal > 0 ? $this->pct($completed, $denominator->count()) : null,
                'certified' => $certified,
                'certified_pct' => $this->pct($certified, $denominator->count()),
                'no_lessons' => $lessonsTotal === 0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['denominator'] <=> $a['denominator']);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupCompletion(float $ratio): array
    {
        $groupLessons = Lesson::query()
            ->whereNotNull('group_id')
            ->get(['id', 'group_id'])
            ->groupBy('group_id');

        if ($groupLessons->isEmpty()) {
            return [];
        }

        $members = DB::table('group_user')
            ->join('users', 'users.id', '=', 'group_user.user_id')
            ->where(function ($q) {
                $q->whereNull('users.role')->orWhereNotIn('users.role', array_keys(Roles::all()));
            })
            ->select('group_user.group_id', 'group_user.user_id')
            ->get()
            ->groupBy('group_id');

        $groupNames = Group::query()->pluck('name', 'id');

        $rows = [];
        foreach ($groupLessons as $groupId => $lessons) {
            $groupId = (int) $groupId;
            $denominator = collect($members[$groupId] ?? [])->pluck('user_id')->unique();
            if ($denominator->isEmpty()) {
                continue;
            }

            $lessonIds = $lessons->pluck('id')->all();
            $needed = (int) ceil(count($lessonIds) * $ratio);

            // Тот же пивот, что и у курсов: lesson_views.is_completed на проде мёртв.
            $done = DB::table('lesson_user')
                ->whereIn('lesson_id', $lessonIds)
                ->whereIn('user_id', $denominator->all())
                ->where('is_completed', true)
                ->select('user_id', DB::raw('COUNT(*) as c'))
                ->groupBy('user_id')
                ->pluck('c', 'user_id');

            $completed = 0;
            foreach ($denominator as $userId) {
                if ((int) ($done[(int) $userId] ?? 0) >= $needed) {
                    $completed++;
                }
            }

            $rows[] = [
                'group_id' => $groupId,
                'name' => (string) ($groupNames[$groupId] ?? ('Поток #'.$groupId)),
                'denominator' => $denominator->count(),
                'reliable' => $denominator->count() >= $this->minDenominator(),
                'lessons_total' => count($lessonIds),
                'lessons_needed' => $needed,
                'completed' => $completed,
                'completed_pct' => $this->pct($completed, $denominator->count()),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['denominator'] <=> $a['denominator']);

        return $rows;
    }

    // ── Выборки ─────────────────────────────────────────────────────────────

    /**
     * Из переданных id оставить только учеников: сотрудники (роли из
     * {@see Roles::all()}) в знаменатель активации не попадают.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function studentIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where(function ($q) {
                $q->whereNull('role')->orWhereNotIn('role', array_keys(Roles::all()));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function everLoggedIn(array $userIds): array
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->where(function ($q) {
                $q->where('login_count', '>', 0)->orWhereNotNull('last_login_at');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Первое «осмысленное действие» = первое открытие урока (LessonView).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, Carbon>
     */
    private function firstLessonViewAt(array $userIds): array
    {
        $out = [];
        LessonView::query()
            ->whereIn('user_id', $userIds)
            ->select('user_id', DB::raw('MIN(first_opened_at) as first_at'))
            ->groupBy('user_id')
            ->get()
            ->each(function ($row) use (&$out) {
                if ($row->first_at !== null) {
                    $out[(int) $row->user_id] = Carbon::parse($row->first_at);
                }
            });

        return $out;
    }

    /**
     * Первая СДАННАЯ домашняя работа: draft не считается сдачей.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, Carbon>
     */
    private function firstHomeworkAt(array $userIds): array
    {
        $out = [];
        HomeworkSubmission::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', self::SUBMITTED_HOMEWORK_STATUSES)
            ->select('user_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('user_id')
            ->get()
            ->each(function ($row) use (&$out) {
                if ($row->first_at !== null) {
                    $out[(int) $row->user_id] = Carbon::parse($row->first_at);
                }
            });

        return $out;
    }

    /**
     * Сколько уроков курса ученик прошёл.
     *
     * Источник — пивот `lesson_user.is_completed` ({@see User::completedLessons()}),
     * куда пишет StudentController::completeLesson(). НЕ `lesson_views.is_completed`:
     * миграция обещала синхронизацию с пивотом, но её никто не делает — на проде
     * 01-09-2026 в lesson_views 649 строк и РОВНО 0 с is_completed, а в lesson_user
     * 166 пройденных уроков. Метрика по lesson_views показывала бы 0 % на каждом
     * курсе и выглядела бы как честный ноль (H3764).
     *
     * @return array<int, array<int, int>>
     */
    private function completedLessonCounts(): array
    {
        $out = [];
        DB::table('lesson_user')
            ->join('lessons', 'lessons.id', '=', 'lesson_user.lesson_id')
            ->where('lesson_user.is_completed', true)
            ->select('lessons.course_id', 'lesson_user.user_id', DB::raw('COUNT(*) as c'))
            ->groupBy('lessons.course_id', 'lesson_user.user_id')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->course_id][(int) $row->user_id] = (int) $row->c;
            });

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function paidStudentsPerCourse(): array
    {
        return DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->whereNotNull('payments.course_id')
            ->where('payments.amount', '>', 0)
            ->where(function ($q) {
                $q->whereNull('users.role')->orWhereNotIn('users.role', array_keys(Roles::all()));
            })
            ->select('payments.course_id', DB::raw('COUNT(DISTINCT payments.user_id) as c'))
            ->groupBy('payments.course_id')
            ->pluck('c', 'course_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function certifiedStudentsPerCourse(): array
    {
        $out = [];
        Certificate::query()
            ->select('course_id', 'user_id')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->course_id][] = (int) $row->user_id;
            });

        return $out;
    }

    // ── Мелочи ──────────────────────────────────────────────────────────────

    private function minDenominator(): int
    {
        return max(1, (int) config('activation_metrics.min_denominator', 5));
    }

    private function completionRatio(): float
    {
        $ratio = (float) config('activation_metrics.completion_lesson_ratio', 0.8);

        return $ratio > 0 && $ratio <= 1 ? $ratio : 0.8;
    }

    /** Доля в процентах или null, если знаменатель пуст (не 0 %, а «нет данных»). */
    private function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : round(((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 1);
    }
}
