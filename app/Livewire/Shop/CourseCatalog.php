<?php

declare(strict_types=1);

namespace App\Livewire\Shop;

use App\Models\Category;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
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

    /** Размер порции при подгрузке */
    public int $perPage = 24;

    /** Сколько курсов сейчас показано */
    public int $loadedCount = 24;

    /** Изменилось ли что-то из фильтров — сбрасываем счётчик */
    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'teacherId', 'format'], true)) {
            $this->resetLoaded();
        }
    }

    public function toggleCategory(int $id): void
    {
        $key = array_search($id, $this->categoryIds, true);

        if ($key === false) {
            $this->categoryIds[] = $id;
        } else {
            unset($this->categoryIds[$key]);
            $this->categoryIds = array_values($this->categoryIds);
        }

        $this->resetLoaded();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'categoryIds', 'teacherId', 'format']);
        $this->resetLoaded();
    }

    /** Подгрузить следующую порцию */
    public function loadMore(): void
    {
        $this->loadedCount += $this->perPage;
    }

    private function resetLoaded(): void
    {
        $this->loadedCount = $this->perPage;
    }

    /** Базовый запрос с применёнными фильтрами — переиспользуется для count и для выборки */
    private function baseQuery()
    {
        return Course::query()
            ->where('is_visible', true)
            ->when($this->search !== '', function ($q) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $this->search);
                $q->where('title', 'LIKE', '%' . $escaped . '%');
            })
            ->when(!empty($this->categoryIds), function ($q) {
                $q->whereHas('categories', fn ($qq) =>
                    $qq->whereIn('categories.id', $this->categoryIds)
                );
            })
            ->when($this->teacherId !== '', fn ($q) => $q->where('teacher_id', $this->teacherId))
            ->when(in_array($this->format, ['live', 'recorded'], true),
                fn ($q) => $q->where('format', $this->format)
            );
    }

    #[Computed]
    public function categories()
    {
        return Category::query()
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->withCount(['courses' => fn ($q) => $q->where('is_visible', true)])
            ->get();
    }

    #[Computed]
    public function teachers()
    {
        return Teacher::query()
            ->whereHas('courses', fn ($q) => $q->where('is_visible', true))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || !empty($this->categoryIds)
            || $this->teacherId !== ''
            || $this->format !== '';
    }

    public function render(): View
    {
        // Общее количество подходящих под фильтр — для счётчика и определения "есть ли ещё"
        $totalCount = $this->baseQuery()->count();

        // Защита от случая, когда фильтр сильно сократил выдачу,
        // а loadedCount остался большим — отдадим в шаблон корректный лимит
        $effectiveLimit = min($this->loadedCount, $totalCount);

        $courses = $this->baseQuery()
            ->with([
                'tariffs'    => fn ($q) => $q->where('is_active', true)->orderBy('price'),
                'teacher:id,name',
                'categories:id,name,slug,color,icon',
            ])
            ->latest('id')
            ->limit($this->loadedCount)
            ->get();

        $hasMore = $totalCount > $courses->count();

        $purchasedByCourse = [];
        if (Auth::check()) {
            $purchasedByCourse = Payment::query()
                ->where('user_id', Auth::id())
                ->whereIn('course_id', $courses->pluck('id'))
                ->whereIn('status', ['paid', 'success'])
                ->get(['course_id', 'tariff'])
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->pluck('tariff')->filter()->unique()->values()->all())
                ->all();
        }

        return view('livewire.shop.course-catalog', [
            'courses'           => $courses,
            'totalCount'        => $totalCount,
            'hasMore'           => $hasMore,
            'purchasedByCourse' => $purchasedByCourse,
        ]);
    }
}