<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\ExamScore;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Автовыдача сертификатов/справок по вехам — единственный источник логики
 * «кто получает». Команды и Filament-actions только вызывают его.
 *
 * Триггеры описаны в CertificateMilestone (block / lesson_count / textbook_lesson
 * / material_count / manual). Block-вехи курс-уровневые; lesson-триггеры
 * пер-групповые (MilestoneIssueTarget): группа дошла до N-го занятия → документы
 * оплатившим участникам ИМЕННО этой группы.
 *
 * Право на документ — payment-driven, зеркалит семантику ключей доступа
 * Lesson::unlockingKeys(), но СТРОЖЕ: блок N считается оплаченным только при
 * 'full', 'block_N' или ОБЕИХ половинах block_N_h1 + block_N_h2. Для
 * lesson-триггеров множество требуемых блоков выводится из block_number уроков
 * окна вехи; если разметки блоков у уроков нет — фолбэк: хотя бы один
 * реальный оплаченный платёж по курсу.
 *
 * Conditional-платежи (is_conditional, доступ «под обещание») уроки открывают,
 * но оплатой не являются → scopeReal() обязателен. Нулевые sibling-платежи
 * access_grant_# от BlockAccessMaterializer — не conditional, поэтому bundles
 * с диапазоном блоков покрываются автоматически.
 */
class MilestoneCertificateIssuer
{
    /**
     * Все «созревшие» цели выдачи на дату: активные автовыдаваемые вехи,
     * чей триггер уже сработал, но не раньше lookback-окна
     * (certificate_auto_issue_lookback_days) — чтобы включение тумблера не
     * осыпало документами давно закончившиеся потоки. $ignoreLookback — для
     * бэкафилла (разовый проход всей истории командой).
     *
     * @return Collection<int, MilestoneIssueTarget>
     */
    public function dueTargets(?CarbonInterface $today = null, bool $ignoreLookback = false): Collection
    {
        $today = $today ?? today();
        $lookbackDays = (int) (MarketingSetting::cached()?->certificate_auto_issue_lookback_days ?? 14);
        $windowStart = $ignoreLookback ? null : $today->copy()->subDays($lookbackDays)->startOfDay();
        $dayStart = $today->copy()->startOfDay();

        $milestones = CertificateMilestone::query()
            ->where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->where('trigger_type', '!=', CertificateMilestone::TRIGGER_MANUAL)
            ->with('course')
            ->get();

        return $milestones
            ->flatMap(function (CertificateMilestone $m) use ($today, $dayStart, $windowStart): array {
                if ($m->trigger_type === CertificateMilestone::TRIGGER_BLOCK) {
                    $endsAt = $m->endBlock()?->ends_at;
                    $due = $endsAt !== null
                        && $endsAt->lt($dayStart)
                        && ($windowStart === null || $endsAt->gte($windowStart));

                    return $due ? [new MilestoneIssueTarget($m)] : [];
                }

                return $this->dueGroupTargets($m, $today, $dayStart, $windowStart);
            })
            ->values();
    }

    /**
     * Пер-групповые цели lesson-триггеров: итерации, чья триггер-дата
     * (lesson_date последнего занятия окна) уже прошла и попадает в окно.
     *
     * @return array<int, MilestoneIssueTarget>
     */
    private function dueGroupTargets(
        CertificateMilestone $m,
        CarbonInterface $today,
        CarbonInterface $dayStart,
        ?CarbonInterface $windowStart,
    ): array {
        $targets = [];

        foreach ($this->courseGroups($m) as $group) {
            foreach ($this->readyOccurrences($m, $group, $today) as $occurrence => $triggerDate) {
                if ($triggerDate === null) {
                    continue;
                }
                $due = $triggerDate->lt($dayStart)
                    && ($windowStart === null || $triggerDate->gte($windowStart));
                if ($due) {
                    $targets[] = new MilestoneIssueTarget($m, $group, $occurrence);
                }
            }
        }

        return $targets;
    }

    /**
     * Готовые итерации вехи для группы: occurrence => дата триггера.
     * «Занятие состоялось» = lesson_date <= сегодня; is_published не
     * учитывается — публикация ждёт монтажа, документ ждать не должен.
     *
     * @return array<int, CarbonInterface|null>
     */
    public function readyOccurrences(CertificateMilestone $m, Group $group, ?CarbonInterface $today = null): array
    {
        $today = $today ?? today();

        if ($m->trigger_type === CertificateMilestone::TRIGGER_TEXTBOOK) {
            $date = $m->groupLessons($group)
                ->whereNotNull('textbook_lesson')
                ->where('textbook_lesson', '>=', (int) $m->trigger_lesson)
                ->whereDate('lesson_date', '<=', $today)
                ->min('lesson_date');

            return $date ? [1 => Carbon::parse($date)] : [];
        }

        $lessons = $m->groupLessons($group)
            ->whereNotNull('lesson_date')
            ->whereDate('lesson_date', '<=', $today);

        if ($m->trigger_type === CertificateMilestone::TRIGGER_MATERIAL) {
            $lessons->where('material_tag', $m->material_tag);
        }

        $dates = $lessons
            ->orderBy('lesson_date')
            ->orderBy('sort_order')
            ->pluck('lesson_date')
            ->values();

        // Повторяющаяся веха: порог итерации = размер окна repeat_every,
        // trigger_lesson не участвует.
        if ($m->isRepeating()) {
            $r = (int) $m->repeat_every;
            $ready = [];
            for ($k = 1; $k * $r <= $dates->count(); $k++) {
                $ready[$k] = $dates[$k * $r - 1];
            }

            return $ready;
        }

        $threshold = (int) $m->trigger_lesson;
        if ($threshold < 1) {
            return [];
        }

        return $dates->count() >= $threshold ? [1 => $dates[$threshold - 1]] : [];
    }

    /**
     * Совместимость v1: вехи, у которых есть хотя бы одна созревшая цель.
     *
     * @return Collection<int, CertificateMilestone>
     */
    public function dueMilestones(?CarbonInterface $today = null): Collection
    {
        return $this->dueTargets($today)
            ->map(fn (MilestoneIssueTarget $t) => $t->milestone)
            ->unique('id')
            ->values();
    }

    /**
     * Выдать по одной цели. Возвращает только СОЗДАННЫЕ Certificate.
     */
    public function issueForTarget(MilestoneIssueTarget $target, bool $force = false): Collection
    {
        $m = $target->milestone;

        if (! $force && ! $m->isAutoIssuable()) {
            return collect();
        }

        return $this->eligibleUsers($m, $target->group, $target->occurrence)
            ->map(fn (User $user): ?Certificate => $this->issueTo($m, $user, $target->group, $target->occurrence))
            ->filter()
            ->values();
    }

    /**
     * Выдать по вехе целиком (Filament-action, тесты). Block/manual-вехи —
     * одна курс-уровневая цель; lesson-триггеры — все ГОТОВЫЕ цели по всем
     * группам. $force снимает окно дат/lookback и гейты автовыдачи, но НЕ
     * снимает «занятия реально состоялись», оплату и членство; «Санка» и с
     * force выдаётся только студентам с баллами exam_scores.
     */
    public function issueForMilestone(CertificateMilestone $milestone, bool $force = false): Collection
    {
        if (! $force && ! $milestone->isAutoIssuable()) {
            return collect();
        }

        $targets = $this->targetsFor($milestone, $force);

        return $targets
            ->flatMap(fn (MilestoneIssueTarget $t) => $this->issueForTarget($t, $force))
            ->values();
    }

    /**
     * Цели вехи. Для force: lesson-триггеры дают все итерации, чьи занятия
     * состоялись (включая сегодняшние), без оглядки на lookback.
     *
     * @return Collection<int, MilestoneIssueTarget>
     */
    private function targetsFor(CertificateMilestone $milestone, bool $force): Collection
    {
        $lessonTypes = [
            CertificateMilestone::TRIGGER_LESSON_COUNT,
            CertificateMilestone::TRIGGER_TEXTBOOK,
            CertificateMilestone::TRIGGER_MATERIAL,
        ];

        if (! in_array($milestone->trigger_type, $lessonTypes, true)) {
            return collect([new MilestoneIssueTarget($milestone)]);
        }

        if (! $force) {
            return $this->dueTargets()
                ->filter(fn (MilestoneIssueTarget $t) => $t->milestone->is($milestone))
                ->values();
        }

        $targets = collect();
        foreach ($this->courseGroups($milestone) as $group) {
            foreach ($this->readyOccurrences($milestone, $group) as $occurrence => $date) {
                $targets->push(new MilestoneIssueTarget($milestone, $group, $occurrence));
            }
        }

        return $targets;
    }

    /**
     * Кому положен документ цели (без учёта уже выданных): активный состав
     * (left_at IS NULL) группы цели — или всех групп курса в статусе
     * active/archived для курс-уровневых вех. Затем фильтр по оплате.
     * forming не участвует; от раскопок старых потоков защищает lookback.
     */
    public function eligibleUsers(CertificateMilestone $milestone, ?Group $group = null, int $occurrence = 1): Collection
    {
        $users = $group
            ? $group->activeUsers()->get()
            : $this->courseGroups($milestone)
                ->flatMap(fn (Group $g) => $g->activeUsers()->get())
                ->unique('id')
                ->values();

        $blocks = $this->requiredBlocks($milestone, $group, $occurrence);

        $users = $users
            ->filter(fn (User $user): bool => $this->hasPaidBlocks($user, (int) $milestone->course_id, $blocks))
            ->reject(fn (User $user): bool => $this->coveredByManualCertificate($milestone, $user))
            ->values();

        // Экзаменационный шаблон: без баллов сертификат не рисуется — выдаём
        // только студентам, чьи баллы уже затянуты из таблицы (exam_scores).
        if ($milestone->template === 'sanka') {
            $scoredUserIds = ExamScore::query()
                ->where('course_id', $milestone->course_id)
                ->pluck('user_id')
                ->flip();

            $users = $users
                ->filter(fn (User $user): bool => $scoredUserIds->has($user->id))
                ->values();
        }

        return $users;
    }

    /** Группы курса, участвующие в выдаче. */
    private function courseGroups(CertificateMilestone $milestone): Collection
    {
        return $milestone->course->groups()
            ->whereIn('status', ['active', 'archived'])
            ->get();
    }

    /**
     * Какие блоки должны быть оплачены для цели. Block-вехи — их диапазон;
     * lesson-триггеры — distinct block_number уроков окна итерации;
     * textbook — уроков с textbook_lesson <= порога. Пустой результат означает
     * «разметки блоков нет» → hasPaidBlocks применит фолбэк.
     *
     * @return array<int, int>
     */
    public function requiredBlocks(CertificateMilestone $m, ?Group $group, int $occurrence = 1): array
    {
        if ($m->trigger_type === CertificateMilestone::TRIGGER_BLOCK
            || $m->trigger_type === CertificateMilestone::TRIGGER_MANUAL) {
            return ($m->start_block && $m->end_block)
                ? range((int) $m->start_block, (int) $m->end_block)
                : [];
        }

        if (! $group) {
            return [];
        }

        if ($m->trigger_type === CertificateMilestone::TRIGGER_TEXTBOOK) {
            return $m->groupLessons($group)
                ->whereNotNull('textbook_lesson')
                ->where('textbook_lesson', '<=', (int) $m->trigger_lesson)
                ->whereNotNull('block_number')
                ->distinct()
                ->pluck('block_number')
                ->map(fn ($n) => (int) $n)
                ->all();
        }

        $lessons = $m->groupLessons($group)
            ->whereNotNull('lesson_date');

        if ($m->trigger_type === CertificateMilestone::TRIGGER_MATERIAL) {
            $lessons->where('material_tag', $m->material_tag);
        }

        $window = $m->lessonWindow($occurrence);

        return $lessons
            ->orderBy('lesson_date')
            ->orderBy('sort_order')
            ->pluck('block_number')
            ->slice($window['from'] - 1, $window['to'] - $window['from'] + 1)
            ->filter(fn ($n) => $n !== null)
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Оплачены ли блоки (правила ключей — в докблоке класса). Пустой $blocks —
     * фолбэк: хотя бы один реальный оплаченный платёж по курсу.
     */
    public function hasPaidBlocks(User $user, int $courseId, array $blocks): bool
    {
        $keys = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->paid()
            ->real()
            ->pluck('tariff')
            ->filter()
            ->all();

        if (in_array('full', $keys, true)) {
            return true;
        }

        if ($blocks === []) {
            return $keys !== [];
        }

        foreach ($blocks as $n) {
            $blockPaid = in_array("block_{$n}", $keys, true)
                || (in_array("block_{$n}_h1", $keys, true) && in_array("block_{$n}_h2", $keys, true));

            if (! $blockPaid) {
                return false;
            }
        }

        return true;
    }

    /** Совместимость v1: оплачены ли все блоки block-вехи. */
    public function hasPaidMilestone(CertificateMilestone $milestone, User $user): bool
    {
        return $this->hasPaidBlocks(
            $user,
            (int) $milestone->course_id,
            $this->requiredBlocks($milestone, null, 1),
        );
    }

    /**
     * Ручной итоговый сертификат (milestone NULL) уже закрывает курс — если
     * block-веха покрывает все блоки курса, второй «итоговый» не выдаём.
     * Прочие типы вех и промежуточные block-вехи ручной сертификат не блокирует.
     */
    private function coveredByManualCertificate(CertificateMilestone $milestone, User $user): bool
    {
        if ($milestone->trigger_type !== CertificateMilestone::TRIGGER_BLOCK
            || ! $this->coversWholeCourse($milestone)) {
            return false;
        }

        return Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $milestone->course_id)
            ->whereNull('certificate_milestone_id')
            ->exists();
    }

    private function coversWholeCourse(CertificateMilestone $milestone): bool
    {
        $numbers = $milestone->course->blocks()->pluck('number');

        return $numbers->isNotEmpty()
            && $milestone->start_block !== null
            && $milestone->end_block !== null
            && $milestone->start_block <= $numbers->min()
            && $milestone->end_block >= $numbers->max();
    }

    /** Идемпотентная выдача одному студенту; null — документ уже был. */
    private function issueTo(CertificateMilestone $milestone, User $user, ?Group $group, int $occurrence): ?Certificate
    {
        $attributes = [
            'student_name' => $user->name,
            'course_title' => $milestone->snapshotTitleFor($occurrence),
            'template' => $milestone->template,
            'document_type' => $milestone->document_type,
            'group_id' => $group?->id,
        ];

        // Баллы «Санки» — снапшот из exam_scores на момент выдачи; только в
        // create-часть, существующий документ обновление баллов не трогает.
        if ($milestone->template === 'sanka') {
            $score = ExamScore::query()
                ->where('user_id', $user->id)
                ->where('course_id', $milestone->course_id)
                ->first();
            if (! $score) {
                return null;
            }
            $attributes['score_clarity'] = $score->score_clarity;
            $attributes['score_letters'] = $score->score_letters;
            $attributes['score_flow'] = $score->score_flow;
        }

        try {
            $certificate = Certificate::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $milestone->course_id,
                    'certificate_milestone_id' => $milestone->id,
                    'occurrence' => $occurrence,
                ],
                $attributes,
            );
        } catch (QueryException) {
            // Гонка двух процессов: unique-индекс отработал, документ уже есть.
            return null;
        }

        return $certificate->wasRecentlyCreated ? $certificate : null;
    }
}
