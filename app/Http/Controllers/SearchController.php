<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * H7590 — страница полных результатов глобального поиска.
 *
 * Канонический URL — path-style /poisk/{q} (вердикт MG 01-11-2026: query-string
 * плох для SEO); GET /poisk?q=… из no-JS-формы редиректится сюда же 301-м.
 * Корпус тот же, что у клиентского индекса (SearchIndexBuilder::build):
 * лендинги + курсы + преподаватели + статьи. Совпадения — LIKE по полям,
 * которые видит пользователь (заголовок/роль/категория).
 */
class SearchController extends Controller
{
    public function form(Request $request)
    {
        // No-JS форма шлёт GET /poisk?q=… — отдаём канонический path-style.
        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            return $this->canonicalRedirect($q);
        }

        return view('search.form');
    }

    public function results(Request $request, string $q)
    {
        $q = trim($q);

        if ($q === '') {
            return redirect()->route('search.form');
        }

        $like = '%'.str_replace(['%', '_', '\\'], ['\%', '\_', '\\\\'], $q).'%';

        // Курсы: витринные лендинги + публичные страницы /k/{slug} (записи-дубли
        // H3807 не продвигаем, как и в клиентском индексе).
        $courses = LandingPage::query()
            ->where('is_active', true)
            ->where('is_listed', true)
            ->where('title', 'like', $like)
            ->orderBy('title')
            ->get(['title', 'slug'])
            ->map(fn (LandingPage $landing) => [
                'title' => $landing->title,
                'url' => '/'.$landing->slug,
                'hint' => null,
            ]);

        $courses = $courses->merge(
            Course::query()
                ->where('is_visible', true)
                ->whereNull('recording_of_course_id')
                ->with('teacher:id,name')
                ->where('title', 'like', $like)
                ->orderBy('title')
                ->get(['id', 'title', 'slug', 'teacher_id'])
                ->map(fn (Course $course) => [
                    'title' => $course->title,
                    'url' => '/k/'.$course->slug,
                    'hint' => $course->teacher?->name,
                ]),
        );

        $teachers = Teacher::query()
            ->withPublicPage()
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('page_role', 'like', $like);
            })
            ->orderBy('name')
            ->get(['name', 'page_slug', 'page_role'])
            ->map(fn (Teacher $teacher) => [
                'title' => $teacher->name,
                'url' => '/prepodavately/'.$teacher->page_slug,
                'hint' => $teacher->page_role,
            ]);

        $articles = Article::query()
            ->published()
            ->with('category:id,title')
            ->where(function ($query) use ($like): void {
                $query->where('title', 'like', $like)
                    ->orWhere('subtitle', 'like', $like)
                    ->orWhere('excerpt', 'like', $like);
            })
            ->latest('published_at')
            ->get(['id', 'category_id', 'slug', 'title', 'subtitle'])
            ->map(fn (Article $article) => [
                'title' => $article->title,
                'url' => '/s/'.$article->slug,
                'hint' => $article->category?->title ?: ($article->subtitle ?: null),
            ]);

        return view('search.results', [
            'q' => $q,
            'courses' => $courses->values(),
            'teachers' => $teachers->values(),
            'articles' => $articles->values(),
        ]);
    }

    protected function canonicalRedirect(string $q): RedirectResponse
    {
        return redirect()->to('/poisk/'.rawurlencode($q), 301);
    }
}
