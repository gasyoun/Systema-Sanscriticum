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
 * виртуальный ключ — но ТОЛЬКО для курсов с `club_included = true` и только
 * пока период членства жив. Виртуальный ключ нигде не сохраняется: он живёт
 * в пределах запроса, поэтому истёкшее членство закрывает доступ немедленно,
 * без миграции данных и без «забытых» строк в payments.
 *
 * H2886: этот ключ больше не константа `full` — его берут из
 * `courses.club_access_key`, поэтому полка умеет отдавать один блок курса
 * (`block_N`), а не только курс целиком. Пусто в колонке = `full`.
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

    /** Действующее членство. Кэш на экземпляр: гейты дёргают это в цикле по урокам. */
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
     * Ключ доступа, который клуб даёт для этого курса. `full` — весь курс;
     * `block_N` / `block_N_hH` — только соответствующий блок
     * (Lesson::unlockingKeys). Пусто в колонке = `full`, прежнее поведение.
     *
     * H2886: раньше здесь стояла константа `full`, и полка умела ровно одно —
     * «курс целиком». Это делало невыразимым реальный запрос: положить в клуб
     * НАЧАЛЬНЫЙ БЛОК Бюлера, а не 31 урок по ₽8 000 за блок. Форма ключа не
     * изобретается — это те же `block_N`, которыми уже пользуются 1 085 боевых
     * тарифов.
     */
    public function accessKeyFor(Course $course): string
    {
        $key = trim((string) ($course->club_access_key ?? ''));

        return $key === '' ? 'full' : $key;
    }

    /**
     * Виртуальные ключи доступа поверх оплаченных.
     *
     * Асимметрия ниже НАМЕРЕННА: coversCourse() остаётся булевым и продолжает
     * пускать члена на страницу курса и урока (гейт ВИДИМОСТИ), а что именно
     * проиграется — решает ключ. Поэтому при `block_1` член видит курс и список
     * уроков, открыт первый блок, остальное заперто обычным платным гейтом.
     * Разводить эти две роли — не недосмотр: право «видеть курс» и право
     * «открыть урок» в этой системе всегда были разными вопросами.
     *
     * @return list<string>
     */
    public function extraTariffKeys(?User $user, ?Course $course): array
    {
        return $this->coversCourse($user, $course) ? [$this->accessKeyFor($course)] : [];
    }

    /**
     * Человекочитаемый объём клубного доступа — для карточки полки, чтобы
     * «Логика (2024)» в списке не читалась как весь курс, когда клуб отдаёт
     * один блок. NULL = весь курс, подпись не нужна.
     */
    public function scopeLabelFor(Course $course): ?string
    {
        $key = $this->accessKeyFor($course);

        if ($key === 'full') {
            return null;
        }

        if (preg_match('/^block_(\d+)(?:_h(\d+))?$/', $key, $m) === 1) {
            return isset($m[2]) && $m[2] !== ''
                ? 'блок '.$m[1].', часть '.$m[2]
                : 'блок '.$m[1];
        }

        return $key;
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
