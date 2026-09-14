<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

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

    /** @var array<string, list<string>> */
    private array $tableCache = [];

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
            if (! $this->isShellCourse($course)) {
                continue;
            }

            $rows[] = $this->courseRow($course);
        }

        return $rows;
    }

    /**
     * Вердикт по ОДНОМУ курсу — та же строка, что попадает в отчёт.
     *
     * Публичный, потому что `catalog:retire-shell` (H3807) обязан переспросить
     * аудит непосредственно перед записью: между отчётом и правкой ростер
     * успевает измениться, и «безопасно» вчерашнего прогона ничего не значит.
     *
     * @return array<string, mixed>
     */
    public function courseRow(Course $course): array
    {
        $blockers = $this->courseBlockers($course);

        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'format' => $course->format,
            'visible' => (bool) $course->is_visible,
            'enrolled' => $course->users()->count(),
            'curator_gated_sale' => $this->isCuratorGatedSale($course),
            'blockers' => $blockers,
            'safe' => $blockers === [],
        ];
    }

    /**
     * Курс — оболочка: ни одной собственной строки данных (см. isShell).
     *
     * Курируемая продажа оболочкой не бывает НИКОГДА, и сказано это здесь
     * ЯВНО (H3812/H3820). Формально проверка `isShell` такой курс и так не
     * пропустит: активный тариф — строка в `tariffs` со столбцом `course_id`.
     * Но это совпадение, а не правило: аудит считает СТРОКИ, а вопрос здесь
     * про ПРОДАЖУ. 31-08-2026 сессия рассуждала ровно наоборот («курс скрыт —
     * значит продаться не может») и погасила пять живых тарифов; правило,
     * которое держится на побочном эффекте пересчёта таблиц, такую ошибку не
     * ловит и никому её не объясняет.
     */
    public function isShellCourse(Course $course): bool
    {
        if ($this->isCuratorGatedSale($course)) {
            return false;
        }

        return $this->isShell((int) $course->id);
    }

    /**
     * Курс скрыт с витрины, но ПРОДАЁТСЯ по прямой ссылке куратора.
     *
     * `/checkout/{tariff}` связывает ТАРИФ и никогда не читает
     * `Course.is_visible`: покупку открывает и закрывает только
     * `tariffs.is_active`. Так школа продаёт запись доверенному студенту на
     * ограниченный срок.
     *
     * Класс задаётся СОСТОЯНИЕМ, а не номером курса. Курс 327 («Йога-сутры … в
     * записи») был случаем инцидента 31-08-2026 и тогда эту форму имел; замер
     * прода 01-09-2026 показывает у него `visible=1` при 5 активных тарифах и
     * 129 оплатах — то есть под класс он сейчас НЕ подпадает, и маркер,
     * молчащий на 327 сегодня, работает верно.
     */
    public function isCuratorGatedSale(Course $course): bool
    {
        if ((bool) $course->is_visible || ! (bool) $course->is_active) {
            return false;
        }

        return $course->tariffs()->where('is_active', true)->exists();
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

    /**
     * Таблицы, которые НЕ считаются содержимым: чистые связки и таксономия.
     * Всё остальное, что ссылается на курс или группу, — данные, и их наличие
     * запрещает удаление.
     */
    private const LINK_TABLES = [
        'course_user',        // запись на курс — разбирается правилом «близнеца»
        'course_group',       // связка курс↔группа
        'category_course',    // таксономия витрины
        'course_slug_aliases', // редиректы старых слагов
        'group_user',         // состав группы — проверяется отдельно
    ];

    /**
     * Оболочка = НИ ОДНОЙ строки ни в одной таблице, ссылающейся на курс,
     * кроме чистых связок.
     *
     * Список таблиц берётся ИЗ СХЕМЫ, а не руками: `course_id` есть в 38
     * таблицах (сертификаты, экзамены, домашки, тарифы, сделки, выплаты…), и
     * перечислять их вручную — гарантированный способ однажды не заметить одну
     * и стереть данные. Ровно тот риск, который MG назвал недопустимым.
     */
    private function isShell(int $courseId): bool
    {
        return $this->firstNonEmptyTable('course_id', $courseId) === null;
    }

    /**
     * Таблицы со столбцом $column, кроме чистых связок.
     *
     * @return list<string>
     */
    private function referencingTables(string $column): array
    {
        return $this->tableCache[$column] ??= collect(Schema::getTableListing())
            ->map(fn (string $t) => str_contains($t, '.') ? substr((string) strrchr($t, '.'), 1) : $t)
            ->reject(fn (string $t) => in_array($t, self::LINK_TABLES, true))
            ->filter(fn (string $t) => in_array($column, Schema::getColumnListing($t), true))
            ->values()
            ->all();
    }

    /**
     * Первая таблица, в которой у объекта есть данные (для объяснения вердикта).
     */
    private function firstNonEmptyTable(string $column, int $id): ?string
    {
        foreach ($this->referencingTables($column) as $table) {
            if (DB::table($table)->where($column, $id)->exists()) {
                return $table;
            }
        }

        return null;
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

        // Скрытость — НЕ доказательство ненужности. См. isCuratorGatedSale():
        // продажу держат тарифы, а не витрина, и «скрыть» такой курс уже
        // «скрыли» — он всё равно продаётся.
        if ($this->isCuratorGatedSale($course)) {
            $blockers[] = sprintf(
                'курс скрыт с витрины, но ПРОДАЁТСЯ по прямой ссылке куратора: активных тарифов — %d. '
                .'`/checkout/{tariff}` связывает тариф и не читает `Course.is_visible`, поэтому `tariffs.is_active` — гейт ПОКУПКИ, а не доступа. '
                .'Ни удалять, ни сводить, ни гасить тарифы',
                $course->tariffs()->where('is_active', true)->count(),
            );
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

    /**
     * Пустая группа = ни состава, ни единой строки в любой таблице со
     * столбцом `group_id`. Список опять из схемы: помимо очевидных занятий и
     * уроков туда входят СЕРТИФИКАТЫ и telegram-хуки уроков — удалив группу
     * по трём проверкам, можно было бы осиротить выданный сертификат.
     */
    private function isEmptyGroup(Group $group): bool
    {
        if (DB::table('group_user')->where('group_id', $group->id)->exists()) {
            return false;
        }

        return $this->firstNonEmptyTable('group_id', (int) $group->id) === null;
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
