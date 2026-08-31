<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSlugAlias;
use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Вывод курса-оболочки с витрины БЕЗ удаления (H3807, остаток H3773).
 *
 * Оболочка — член семьи без единой собственной строки данных: ни блоков, ни
 * тарифов, ни оплат, ни домашек. Такие курсы всё равно нельзя стереть с ходу:
 * на курсе 421 «Караки по Панини 2025-2026 в записи» девять записанных, и
 * условие MG 19-08-2026 жёсткое — «главное, чтобы не потерялись никакие записи
 * ни при каких обстоятельствах».
 *
 * Поэтому здесь ровно три шага, и ни один из них ничего не уничтожает:
 *
 *   1. каждая запись оболочки продублирована на живой курс семьи (уже есть —
 *      ничего не делаем; нет — добавляем, сохраняя pivot-поля);
 *   2. оболочка снимается с витрины (`is_visible = false`) — доступ в кабинет
 *      от этого не пропадает, см. StudentController: `is_visible` там намеренно
 *      не фильтрует;
 *   3. слаг оболочки заводится алиасом на живой курс, чтобы после будущего
 *      удаления старые ссылки давали 301, а не 404.
 *
 * Записи с оболочки НЕ отвязываются. Отвязка ничего не защищает (все девять уже
 * на живом курсе), но стирает след того, кто и когда был записан; строки
 * `course_user` уйдут сами каскадом в тот день, когда человек решит удалять
 * курс. Удаление — отдельный проход, эта служба его не делает и делать не будет.
 */
final class CatalogShellRetirement
{
    public function __construct(
        private readonly CatalogShellAudit $audit,
        private readonly CourseFamilyMatcher $families,
    ) {}

    /**
     * Что произойдёт. Ничего не пишет — это же тело возвращается после apply().
     *
     * @return array{
     *     shell: array<string, mixed>,
     *     target: array<string, mixed>,
     *     enrolments: array{covered: list<int>, to_move: list<int>},
     *     visibility: array{change: bool, from: bool},
     *     alias: array{create: bool, slug: string, reason: ?string},
     * }
     */
    public function plan(Course $shell, Course $target): array
    {
        $this->guard($shell, $target);

        $shellUserIds = $shell->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $targetUserIds = $target->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $covered = array_values(array_intersect($shellUserIds, $targetUserIds));
        $toMove = array_values(array_diff($shellUserIds, $targetUserIds));

        [$createAlias, $aliasReason] = $this->aliasDecision($shell, $target);

        return [
            'shell' => ['id' => (int) $shell->id, 'title' => (string) $shell->title, 'slug' => (string) $shell->slug],
            'target' => ['id' => (int) $target->id, 'title' => (string) $target->title, 'slug' => (string) $target->slug],
            'enrolments' => ['covered' => $covered, 'to_move' => $toMove],
            'visibility' => ['change' => (bool) $shell->is_visible, 'from' => (bool) $shell->is_visible],
            'alias' => ['create' => $createAlias, 'slug' => (string) $shell->slug, 'reason' => $aliasReason],
        ];
    }

    /**
     * Выполнить план в одной транзакции. Аудит перечитывается ВНУТРИ неё:
     * между отчётом и записью курс мог обзавестись данными.
     *
     * @return array<string, mixed> тот же план, что был исполнен
     */
    public function apply(Course $shell, Course $target): array
    {
        return DB::transaction(function () use ($shell, $target): array {
            $plan = $this->plan($shell, $target);

            foreach ($plan['enrolments']['to_move'] as $userId) {
                $pivot = DB::table('course_user')
                    ->where('course_id', $shell->id)
                    ->where('user_id', $userId)
                    ->first();

                $target->users()->attach($userId, [
                    'status' => $pivot->status ?? null,
                    'note' => $pivot->note ?? null,
                    'left_after_block' => $pivot->left_after_block ?? null,
                    'joined_at_block' => $pivot->joined_at_block ?? null,
                ]);
            }

            if ($plan['visibility']['change']) {
                $shell->forceFill(['is_visible' => false])->save();
            }

            if ($plan['alias']['create']) {
                CourseSlugAlias::query()->firstOrCreate(
                    ['slug' => $plan['alias']['slug']],
                    ['course_id' => $target->id, 'created_at' => now()],
                );
            }

            return $plan;
        });
    }

    /**
     * Условия, без которых запись недопустима. Каждое — про потерю данных, а не
     * про аккуратность: сорваться должно ДО транзакции и громко.
     */
    private function guard(Course $shell, Course $target): void
    {
        if ((int) $shell->id === (int) $target->id) {
            throw new RuntimeException('Оболочка и живой курс — один и тот же курс.');
        }

        if (! $this->audit->isShellCourse($shell)) {
            throw new RuntimeException(sprintf(
                'Курс %d не оболочка: у него есть собственные данные (блоки, тарифы, оплаты или домашки). Такой курс сводить нельзя.',
                $shell->id,
            ));
        }

        if ($this->audit->isShellCourse($target)) {
            throw new RuntimeException(sprintf(
                'Курс %d сам оболочка — переносить записи на него бессмысленно.',
                $target->id,
            ));
        }

        $shellFamily = $this->families->familyFor($shell);
        $targetFamily = $this->families->familyFor($target);

        if ($shellFamily !== $targetFamily) {
            throw new RuntimeException(sprintf(
                'Разные семьи: %d → «%s», %d → «%s». Свести можно только потоки одной программы.',
                $shell->id, $shellFamily, $target->id, $targetFamily,
            ));
        }
    }

    /**
     * Заводить ли алиас. Канон всегда выигрывает у алиаса (Course::resolveBySlug),
     * поэтому строка, заведённая пока оболочка ещё жива, ничего не ломает — она
     * просто ждёт дня удаления. Не заводим только там, где слаг уже чей-то.
     *
     * @return array{bool, ?string}
     */
    private function aliasDecision(Course $shell, Course $target): array
    {
        $slug = (string) $shell->slug;

        if ($slug === '') {
            return [false, 'у оболочки пустой слаг'];
        }

        $existing = CourseSlugAlias::query()->where('slug', $slug)->first();

        if ($existing === null) {
            return [true, null];
        }

        if ((int) $existing->course_id === (int) $target->id) {
            return [false, 'алиас на этот курс уже есть'];
        }

        return [false, sprintf('слаг уже алиас курса %d — не перевешиваем молча', $existing->course_id)];
    }
}
