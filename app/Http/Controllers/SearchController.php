<?php

namespace App\Http\Controllers;

use App\Support\SearchIndexBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * H7590 — страница полных результатов глобального поиска.
 *
 * Канонический URL — path-style /poisk/{q} (вердикт MG 01-11-2026:
 * query-string плох для SEO); GET /poisk?q=… из no-JS-формы редиректится
 * сюда же 301-м.
 *
 * Корпус — SearchIndexBuilder::build(), тот же, что у клиентского индекса
 * шапки: выдача дропдауна и полная страница видят одинаковые данные.
 * Фильтрация — mb_stripos по title/hint: регистронезависимо для кириллицы
 * на любом драйвере (LIKE на sqlite ASCII-only).
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

        $matched = collect(app(SearchIndexBuilder::class)->build())
            ->filter(fn (array $item): bool => $this->matches($item, $q));

        return view('search.results', [
            'q' => $q,
            'courses' => $matched->filter(fn (array $item) => $item['type'] === 'курс')->values(),
            'teachers' => $matched->filter(fn (array $item) => $item['type'] === 'преподаватель')->values(),
            'articles' => $matched->filter(fn (array $item) => $item['type'] === 'статья')->values(),
        ]);
    }

    protected function matches(array $item, string $q): bool
    {
        foreach (['title', 'hint'] as $field) {
            $haystack = $item[$field] ?? null;
            if (is_string($haystack) && $haystack !== '' && mb_stripos($haystack, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function canonicalRedirect(string $q): RedirectResponse
    {
        return redirect()->to('/poisk/'.rawurlencode($q), 301);
    }
}
