<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Поиск ПУСТЫХ курсов и групп-оболочек — кандидатов на удаление (H3122).
 *
 * Задача поставлена MG 19-08-2026 после того, как «Караки по Панини 2025-2026 в
 * записи» (курс 421) оказался пустым дублем настоящего курса 335: ноль уроков,
 * ноль оплат, а девять человек записаны и там, и там. Условие MG жёсткое:
 * «главное, чтобы не потерялись никакие записи ни при каких обстоятельствах».
 *
 * Поэтому аудит по умолчанию НИЧЕГО не удаляет, а объект попадает в SAFE только
 * когда провалены все попытки доказать, что он нужен. Наивный критерий «нет
 * уроков и оплат» опасен: под него подпадают «Клуб» и «Старт чтения» — рабочие
 * заготовки, на слаги которых ссылается код (177 и 14 упоминаний). Поэтому в
 * проверки входит грep по исходникам, а не только запросы к базе.
 */
class CatalogShellAudit
{
    /** Где ищем упоминания слага: код, конфиги, шаблоны, маршруты, миграции/сиды. */
    private const CODE_DIRS = ['app', 'config', 'resources', 'routes', 'database'];

    public function __construct(private readonly CourseFamilyMatcher $families) {}

    /**
     * @return array{courses: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function report(): array
    {
        return [
            'courses' => $this->courses(),
            'groups' => $this->groups(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function courses(): array
    {
        $rows = [];

        foreach (Course::query()->orderBy('id')->get() as $course) {
            if (! $this->isShell($course->id)) {
                continue;
            }

            $blockers = $this->courseBlockers($course);

            $rows[] = [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'format' => $course->format,
                'visible' => (bool) $course->is_visible,
                'enrolled' => $course->users()->count(),
                'blockers' => $blockers,
                'safe' => $blockers === [],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        $rows = [];

        foreach (Group::query()->orderBy('id')->get() as $group) {
            if (! $this->isEmptyGroup($group)) {
                continue;
            }

            $blockers = $this->groupBlockers($group);

            $rows[] = [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'status' => $group->status,
                'blockers' => $blockers,
                'safe' => $blockers === [],
            ];
        }

        return $rows;
    }

    /** Оболочка = ни одного урока, ни одной оплаты, ни одного занятия в расписании. */
    private function isShell(int $courseId): bool
    {
        return DB::table('lessons')->where('course_id', $courseId)->doesntExist()
            && DB::table('payments')->where('course_id', $courseId)->where('status', 'paid')->doesntExist()
            && DB::table('schedules')->where('course_id', $courseId)->doesntExist();
    }

    /**
     * Причины НЕ удалять курс. Пустой список — удалять безопасно.
     *
     * @return list<string>
     */
    private function courseBlockers(Course $course): array
    {
        $blockers = [];

        if ($refs = $this->codeReferences((string) $course->slug)) {
            $blockers[] = "слаг упоминается в коде ({$refs} шт.) — вероятно, рабочая заготовка, а не дубль";
        }

        $orphans = $this->enrolledWithoutTwin($course);
        if ($orphans !== []) {
            $blockers[] = 'записанные без близнеца: '.implode(', ', $orphans)
                .' — удаление отняло бы у них единственную запись на курс';
        }

        if ((bool) $course->is_visible) {
            $blockers[] = 'курс виден на витрине — сначала скрыть и убедиться, что он не нужен';
        }

        return $blockers;
    }

    /**
     * ID записанных, у которых НЕТ второго курса той же семьи. Пока такие есть,
     * курс удалять нельзя: для них он единственная точка доступа.
     *
     * @return list<int>
     */
    private function enrolledWithoutTwin(Course $course): array
    {
        $family = $this->families->familyFor($course);

        $twinIds = Course::query()
            ->where('id', '!=', $course->id)
            ->get()
            ->filter(fn (Course $other) => $this->families->familyFor($other) === $family)
            ->pluck('id')
            ->all();

        $orphans = [];
        foreach ($course->users()->pluck('users.id') as $userId) {
            $hasTwin = $twinIds !== [] && DB::table('course_user')
                ->where('user_id', $userId)
                ->whereIn('course_id', $twinIds)
                ->exists();

            if (! $hasTwin) {
                $orphans[] = (int) $userId;
            }
        }

        return $orphans;
    }

    private function isEmptyGroup(Group $group): bool
    {
        return DB::table('group_user')->where('group_id', $group->id)->doesntExist()
            && DB::table('schedules')->where('group_id', $group->id)->doesntExist()
            && DB::table('lessons')->where('group_id', $group->id)->doesntExist();
    }

    /** @return list<string> */
    private function groupBlockers(Group $group): array
    {
        $blockers = [];

        if ($refs = $this->codeReferences((string) $group->slug)) {
            $blockers[] = "слаг упоминается в коде ({$refs} шт.)";
        }

        if (DB::table('group_reviewer')->where('group_id', $group->id)->exists()) {
            $blockers[] = 'к группе привязаны проверяющие домашек';
        }

        if (DB::table('waitlist_entries')->where('group_id', $group->id)->exists()) {
            $blockers[] = 'на группу есть заявки листа ожидания';
        }

        return $blockers;
    }

    /**
     * Сколько раз слаг встречается в исходниках. Именно эта проверка отделяет
     * пустой дубль от заготовки под фичу: «Клуб» и «Старт чтения» пусты в базе,
     * но код обращается к ним по слагу.
     */
    private function codeReferences(string $slug): int
    {
        if ($slug === '') {
            return 0;
        }

        $count = 0;
        foreach (self::CODE_DIRS as $dir) {
            $path = base_path($dir);
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if (! in_array($file->getExtension(), ['php', 'blade', 'js', 'json', 'md'], true)) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getRealPath()), $slug)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
