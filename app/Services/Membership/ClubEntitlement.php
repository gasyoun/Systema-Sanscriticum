<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Право клубного члена смотреть записи (H2644) — единственный источник правды
 * для «открыт ли этот курс/урок по клубу».
 *
 * Почему это отдельный слой, а не «просто группа». Спецификация (§6) описывает
 * клуб как «Group ← grantAccess() на обычном тарифе». Для когорты (один курс —
 * одна группа) этого достаточно, для каталога — нет: кабинет открывает урок,
 * только если у студента есть ОПЛАЧЕННЫЙ КЛЮЧ ИМЕННО ЭТОГО КУРСА
 * (Lesson::isUnlockedBy(payments.tariff этого course_id)). Членство в группе
 * такого ключа не даёт, поэтому один лишь клубный Group открыл бы студенту
 * список уроков и ни одного урока. Это разрыв в механике, а не недосмотр
 * спецификации: сквозного права «весь каталог» в системе не существовало.
 *
 * Что делает этот класс: там, где кабинет считает ключи доступа, добавляет
 * виртуальный `full` — но ТОЛЬКО для курсов с `club_included = true` и только
 * пока период членства жив. Виртуальный ключ нигде не сохраняется: он живёт
 * в пределах запроса, поэтому истёкшее членство закрывает доступ немедленно,
 * без миграции данных и без «забытых» строк в payments.
 *
 * Чего он НАМЕРЕННО не делает — не трогает HomeworkController. Клуб не даёт
 * проверки домашних заданий (§6: «no homework review · no curator time»), и
 * то, что этот гейт остался нетронутым, и есть исполнение запрета: клубный
 * член физически не может занять время куратора.
 */
final class ClubEntitlement
{
    private ?Course $clubCourse = null;

    private bool $clubCourseResolved = false;

    /** @var array<int, bool> */
    private array $activeCache = [];

    public function enabled(): bool
    {
        return (bool) config('features.club_membership', false);
    }

    public function clubCourse(): ?Course
    {
        if ($this->clubCourseResolved) {
            return $this->clubCourse;
        }

        $this->clubCourseResolved = true;
        $slug = (string) config('membership.club.course_slug', 'club');
        $this->clubCourse = $slug === '' ? null : Course::query()->where('slug', $slug)->first();

        return $this->clubCourse;
    }

    /** Действующее членство. Кэш на запрос: гейты дёргают это в цикле по урокам. */
    public function isMember(?User $user): bool
    {
        if (! $this->enabled() || ! $user instanceof User) {
            return false;
        }

        return $this->activeCache[$user->id] ??= ClubMembership::query()
            ->where('user_id', $user->id)
            ->active()
            ->exists();
    }

    /**
     * Курс входит в клубную полку. Сам курс-членство в полку не входит: он
     * товар, а не содержимое (иначе клуб «открывал» бы сам себя).
     */
    public function courseIsIncluded(?Course $course): bool
    {
        if (! $course instanceof Course || ! (bool) ($course->club_included ?? false)) {
            return false;
        }

        $club = $this->clubCourse();

        return ! ($club instanceof Course && (int) $club->id === (int) $course->id);
    }

    public function coversCourse(?User $user, ?Course $course): bool
    {
        return $this->courseIsIncluded($course) && $this->isMember($user);
    }

    /**
     * Виртуальные ключи доступа поверх оплаченных. `full` открывает все уроки
     * курса (Lesson::unlockingKeys всегда содержит 'full').
     *
     * @return list<string>
     */
    public function extraTariffKeys(?User $user, ?Course $course): array
    {
        return $this->coversCourse($user, $course) ? ['full'] : [];
    }

    /**
     * Курсы клубной полки для витрины кабинета.
     *
     * @return Collection<int, Course>
     */
    public function shelfFor(?User $user): Collection
    {
        if (! $this->isMember($user)) {
            /** @var Collection<int, Course> $empty */
            $empty = Course::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        $query = Course::query()
            ->where('club_included', true)
            ->where('is_active', true)
            ->orderBy('title');

        $club = $this->clubCourse();
        if ($club instanceof Course) {
            $query->whereKeyNot($club->id);
        }

        return $query->get();
    }
}
