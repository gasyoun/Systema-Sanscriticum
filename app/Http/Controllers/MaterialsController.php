<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lesson;
use App\Support\ProductLadderAnchors;
use App\Support\VideoEmbed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * «Материалы» (H387) — журнальный хаб бесплатного контента НАД магазином
 * (паттерн Arzamas «Журнал/Материалы»). Чистый агрегатор трёх уже существующих
 * источников — статьи блога (/s), бесплатные беседы (open lessons главной) и
 * preview-уроки курсов — в одну сетку типизированных карточек
 * (Текст / Видео / Урок). Никаких новых моделей контента; каждая карточка
 * несёт CTA в воронку: каталог, «С чего начать» или страница курса.
 */
class MaterialsController extends Controller
{
    public function index(): View
    {
        $cards = collect()
            ->concat($this->articleCards())
            ->concat($this->videoCards())
            ->concat($this->previewCards())
            ->sortByDesc('date')
            ->values();

        $counts = [
            'text' => $cards->where('type', 'text')->count(),
            'video' => $cards->where('type', 'video')->count(),
            'lesson' => $cards->where('type', 'lesson')->count(),
        ];

        // Лесенка внизу хаба — тот же партиал и те же честные якоря цен,
        // что и на «С чего начать» (единая точка правды ProductLadderAnchors).
        $ladder = ProductLadderAnchors::resolve();

        $page = new LandingPage([
            'title' => 'Материалы',
            'description' => 'Статьи, бесплатные беседы и открытые уроки о санскрите — читайте и смотрите бесплатно.',
        ]);

        return view('shop.materials', compact('cards', 'counts', 'ladder', 'page'));
    }

    /** Статьи блога → карточки «Текст». CTA — в каталог курсов. */
    private function articleCards(): Collection
    {
        return Article::published()
            ->with('category:id,name,slug')
            ->select([
                // Явный select — не тянем longText body в сетку карточек.
                'id', 'category_id', 'slug', 'title', 'excerpt',
                'cover_path', 'reading_time', 'published_at',
            ])
            ->latest('published_at')
            ->get()
            ->map(fn (Article $article): array => [
                'type' => 'text',
                'title' => $article->title,
                'subtitle' => $article->category?->name,
                'excerpt' => $article->excerpt ? Str::limit($article->excerpt, 120) : null,
                'cover' => $article->cover_url,
                'meta' => $article->reading_time
                    ? $article->reading_time.' мин чтения'
                    : null,
                'url' => route('articles.show', $article->slug),
                'external' => false,
                'cta' => ['label' => 'К курсам', 'url' => route('shop.index')],
                'date' => $article->published_at,
            ]);
    }

    /**
     * Бесплатные беседы (open lessons главной, Lesson::shownOnMain) →
     * карточки «Видео». Ролик открывается на своей платформе; CTA —
     * «С чего начать» (следующий шаг воронки после знакомства).
     */
    private function videoCards(): Collection
    {
        return Lesson::shownOnMain()
            ->with('course:id,slug,title')
            ->latest('lesson_date')
            ->get()
            ->map(function (Lesson $lesson): ?array {
                // Как в open-lessons-carousel: без распознанной видео-ссылки
                // карточка не рендерится — мёртвых кнопок «Смотреть» не бывает.
                $watchUrl = collect([$lesson->youtube_url, $lesson->rutube_url, $lesson->video_url])
                    ->first(fn (?string $url) => $url && VideoEmbed::embed($url));

                if (! $watchUrl) {
                    return null;
                }

                return [
                    'type' => 'video',
                    'title' => $lesson->title,
                    'subtitle' => $lesson->course?->title,
                    'excerpt' => null,
                    'cover' => VideoEmbed::poster($lesson->youtube_url ?: $lesson->video_url ?: $lesson->rutube_url),
                    'meta' => self::formatDuration($lesson->duration_seconds),
                    'url' => $watchUrl,
                    'external' => true,
                    'cta' => ['label' => 'С чего начать', 'url' => route('shop.start')],
                    'date' => $lesson->lesson_date,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Preview-уроки видимых курсов → карточки «Урок»: прямой заход в
     * бесплатный пример урока, CTA — страница его курса (самая короткая воронка).
     */
    private function previewCards(): Collection
    {
        return Course::query()
            ->where('is_visible', true)
            ->whereHas('previewLesson')
            ->with(['previewLesson', 'teacher:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Course $course): array => [
                'type' => 'lesson',
                'title' => $course->previewLesson->title,
                'subtitle' => $course->title,
                'excerpt' => 'Бесплатный пример урока курса — посмотрите подачу до покупки.',
                'cover' => $course->image_path ? Storage::url($course->image_path) : null,
                'meta' => self::formatDuration($course->previewLesson->duration_seconds) ?? 'Пример урока',
                'url' => route('shop.course.preview', $course->slug),
                'external' => false,
                'cta' => ['label' => 'Страница курса', 'url' => route('shop.course.show', $course->slug)],
                'date' => $course->previewLesson->lesson_date ?? $course->created_at,
            ]);
    }

    /** duration_seconds → «1:02:11» / «7:45»; null/0 → null (чип не показывается). */
    private static function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}
