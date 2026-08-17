<?php

declare(strict_types=1);

namespace App\Livewire\Shop;

use App\Models\Category;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Teacher;
use App\Support\FlagshipExperiments;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class CourseCatalog extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Список ID выбранных категорий.
     * Всегда переиндексирован, иначе ломается сериализация в URL.
     */
    #[Url(as: 'cat', except: [])]
    public array $categoryIds = [];

    #[Url(as: 'teacher', except: '')]
    public string $teacherId = '';

    /** Возможные значения: '' | 'live' | 'recorded' */
    #[Url(as: 'format', except: '')]
    public string $format = '';

    /** Уровень: '' | ключ из Course::LEVELS (beginner/continuing/advanced). */
    #[Url(as: 'level', except: '')]
    public string $level = '';

    /**
     * Подсказки «Часто ищут» под строкой поиска. Подбираются под слова,
     * реально встречающиеся в названиях курсов (LIKE по title), чтобы клик
     * не приводил к пустой выдаче.
     */
    public array $popularSearches = ['Грамматика', 'Санскрит', 'Хинди', 'Бхагавад-гита', 'Кочергина'];

    public function toggleCategory(int $id): void
    {
        $key = array_search($id, $this->categoryIds, true);

        if ($key === false) {
            $this->categoryIds[] = $id;
        } else {
            unset($this->categoryIds[$key]);
            $this->categoryIds = array_values($this->categoryIds);
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'categoryIds', 'teacherId', 'format', 'level']);
    }

    /** Сбросить только категории — для чипа «Все» в ленте категорий */
    public function resetCategories(): void
    {
        $this->categoryIds = [];
    }

    /** Базовый запрос с применёнными фильтрами — переиспользуется для count и для выборки */
    private function baseQuery()
    {
        return PrivateArchiveEligibility::scopePublic(Course::query())
            ->where('is_visible', true)
            ->when($this->search !== '', function ($q) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $this->search);
                $q->where('title', 'LIKE', '%'.$escaped.'%');
            })
            ->when(! empty($this->categoryIds), function ($q) {
                $q->whereHas('categories', fn ($qq) => $qq->whereIn('categories.id', $this->categoryIds)
                );
            })
            // Фильтр по преподавателю включает курсы, где он основной ИЛИ со-препод.
            ->when($this->teacherId !== '', fn ($q) => $q->forTeacher((int) $this->teacherId))
            ->when(in_array($this->format, ['live', 'recorded'], true),
                fn ($q) => $q->where('format', $this->format)
            )
            ->when(array_key_exists($this->level, Course::LEVELS),
                fn ($q) => $q->where('level', $this->level)
            );
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->withCount(['courses' => fn ($q) => PrivateArchiveEligibility::scopePublic($q->where('is_visible', true))])
            ->get();
    }

    #[Computed]
    public function teachers()
    {
        return Teacher::query()
            ->whereHas('courses', fn ($q) => PrivateArchiveEligibility::scopePublic($q->where('is_visible', true)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Сколько видимых курсов у каждого уровня — БЕЗ учёта самого фильтра уровня,
     * чтобы чипы не «схлопывались» при выборе. Чипы уровней показываются только
     * когда владелец классифицировал хотя бы один курс.
     */
    #[Computed]
    public function levelCounts(): array
    {
        return PrivateArchiveEligibility::scopePublic(Course::query())
            ->where('is_visible', true)
            ->whereNotNull('level')
            ->selectRaw('level, count(*) as cnt')
            ->groupBy('level')
            ->pluck('cnt', 'level')
            ->all();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || ! empty($this->categoryIds)
            || $this->teacherId !== ''
            || $this->format !== ''
            || $this->level !== '';
    }

    public function render(): View
    {
        // Каталог отдаётся целиком (как у online.synchronize.ru) — без догрузки порциями.
        // При нескольких сотнях курсов это всё ещё один лёгкий запрос.
        $courses = $this->baseQuery()
            ->with([
                'tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('price'),
                'teacher:id,name',
                'categories:id,name,slug,color,icon',
            ])
            ->latest('id')
            ->get();

        $totalCount = $courses->count();

        // Итоги по секциям (формату) для заголовков «Идут сейчас N» / «В записи N».
        $sectionTotals = $courses
            ->groupBy(fn ($c) => $c->format ?: 'other')
            ->map->count();

        $purchasedByCourse = [];
        if (Auth::check()) {
            $purchasedByCourse = Payment::query()
                ->real() // conditional-доступ «под обещание» — не покупка, блок должен остаться оплачиваемым
                ->where('user_id', Auth::id())
                ->whereIn('course_id', $courses->pluck('id'))
                ->paid()
                ->get(['course_id', 'tariff'])
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->pluck('tariff')->filter()->unique()->values()->all())
                ->all();
        }

        $nextStepByCourse = [];
        if (FlagshipExperiments::nextStepEnabled()) {
            $request = request();
            foreach ($courses as $course) {
                if (! FlagshipExperiments::isFlagship($course)) {
                    continue;
                }
                $nextStepByCourse[$course->id] = FlagshipExperiments::nextStepLinks($course);
                FlagshipExperiments::recordCardImpression($course, $request);
            }
        }

        return view('livewire.shop.course-catalog', [
            'courses' => $courses,
            'totalCount' => $totalCount,
            'sectionTotals' => $sectionTotals,
            'purchasedByCourse' => $purchasedByCourse,
            'deposit' => MarketingSetting::cached(),
            'nextStepByCourse' => $nextStepByCourse,
        ]);
    }
}
