<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Course;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Аудит описаний курсов и очередь на редактуру (H7094, 19-10-2026).
 *
 * Зачем: 17 из 98 страниц `/k/` в sitemap имеют пустое `courses.description`,
 * ещё 4 короче 400 символов, а 5 групп делят побайтово одинаковый текст.
 * Canonical для них запрещён — у семейств живые оплаты (42/16, 65–121, 18/47),
 * то есть склейка отняла бы трафик у продающей страницы. Значит, лечится
 * уникальным текстом, а этот сервис даёт очередь и детектор регрессии.
 *
 * Границы — ровно те же, что у sitemap: visible + scopePublic +
 * withOwnCatalogCard. Иначе «аудит» считал бы страницы, которых в выдаче нет.
 */
final class CourseContentAudit
{
    /** Порог «короткого» описания: ниже него страница уходит в очередь. */
    public const SHORT_DESCRIPTION = 400;

    public function query(): Builder
    {
        return PrivateArchiveEligibility::scopePublic(Course::query()->where('is_visible', true))
            ->withOwnCatalogCard();
    }

    /**
     * @return array{checked: int, empty: list<string>, short: list<string>, identical: list<list<string>>}
     */
    public function findings(): array
    {
        $courses = $this->query()->get(['id', 'slug', 'description']);

        $empty = [];
        $short = [];
        $byHash = [];

        foreach ($courses as $course) {
            $description = trim((string) $course->description);

            if ($description === '') {
                $empty[] = (string) $course->slug;

                continue;
            }

            if (mb_strlen($description) < self::SHORT_DESCRIPTION) {
                $short[] = (string) $course->slug;
            }

            $byHash[md5($description)][] = (string) $course->slug;
        }

        $identical = array_values(array_filter($byHash, static fn (array $group): bool => count($group) > 1));

        sort($empty);
        sort($short);

        return [
            'checked' => $courses->count(),
            'empty' => $empty,
            'short' => $short,
            'identical' => $identical,
        ];
    }

    /**
     * Очередь на редактуру: пустые и короткие описания, сначала те, что уже
     * продают. Фильтр в PHP, а не в SQL: `CHAR_LENGTH` есть в MySQL, но не в
     * SQLite, на котором гоняются тесты, — расхождение диалектов тут не нужно.
     *
     * @return Collection<int, Course>
     */
    public function priorityQueue(int $limit = 0): Collection
    {
        $courses = $this->query()
            ->withCount('payments')
            ->get()
            ->filter(static fn (Course $course): bool => mb_strlen(trim((string) $course->description)) < self::SHORT_DESCRIPTION)
            ->sortByDesc(static fn (Course $course): int => (int) ($course->payments_count ?? 0))
            ->values();

        return $limit > 0 ? $courses->take($limit)->values() : $courses;
    }
}
