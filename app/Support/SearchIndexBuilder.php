<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Teacher;
use Illuminate\Support\Facades\Date;

/**
 * H7590 — клиентский индекс глобального поиска (шапка samskrte.ru).
 *
 * Корпус = витринные лендинги (is_active + is_listed, как на главной) +
 * публичные курсы /k/{slug} (is_visible, записи-дубли H3807 не продвигаем) +
 * опубликованные статьи + преподаватели с включённой публичной страницей.
 *
 * Файлы в public/search: index-{hash12}.json (хэш от содержимого — клиент
 * кэширует бессрочно и перекачивает только при смене контента) и manifest.json
 * (какой файл актуален). Прошлые index-*.json подчищаются.
 *
 * В юнит-окружении write() без явного каталога — no-op: тесты не должны
 * писать в public/ репозитория (гвард ниже), тесты вызывают write($tmpDir).
 */
class SearchIndexBuilder
{
    /** Каталог публичного индекса (по умолчанию public/search). */
    public function dir(): string
    {
        return public_path('search');
    }

    /**
     * Актуальный манифест для рендера шапки (URL индекса), null — индекс ещё
     * не построен (поиск деградирует до серверной страницы /poisk/{q}).
     *
     * @return array{file: string, hash: string, count: int, generated_at: string}|null
     */
    public static function manifest(): ?array
    {
        static $memo = null;
        static $memoDone = false;

        if ($memoDone) {
            return $memo;
        }

        $memoDone = true;

        $path = app(self::class)->dir().'/manifest.json';
        if (! is_file($path)) {
            return $memo = null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || empty($decoded['file']) || ! is_string($decoded['file'])) {
            return $memo = null;
        }

        return $memo = $decoded;
    }

    /**
     * Собирает корпус и пишет индекс + манифест. Возвращает итог записи.
     *
     * @return array{file: string, hash: string, count: int, skipped?: bool}
     */
    public function write(?string $dir = null): array
    {
        if ($dir === null) {
            // Юнит-тесты с RefreshDatabase создают модели на каждый кейс —
            // наблюдатели не должны писать реальные файлы в public/.
            if (app()->runningUnitTests()) {
                return ['file' => '', 'hash' => '', 'count' => 0, 'skipped' => true];
            }

            $dir = $this->dir();
        }

        $items = $this->build();
        $json = json_encode(
            ['v' => 1, 'generated_at' => Date::now()->toIso8601String(), 'count' => count($items), 'items' => $items],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $hash = substr(sha1((string) $json), 0, 12);
        $file = "index-{$hash}.json";

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! is_file($dir.'/'.$file)) {
            file_put_contents($dir.'/'.$file, $json);
        }

        file_put_contents($dir.'/manifest.json', json_encode(
            ['file' => $file, 'hash' => $hash, 'count' => count($items), 'generated_at' => Date::now()->toIso8601String()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        // Прошлые версии индекса больше никому не нужны (манифест указывает
        // на новую), подчищаем, чтобы public/search не рос на каждом сохранении.
        foreach (glob($dir.'/index-*.json') ?: [] as $old) {
            if (basename($old) !== $file) {
                @unlink($old);
            }
        }

        return ['file' => $file, 'hash' => $hash, 'count' => count($items)];
    }

    /**
     * Корпус поиска: каждый элемент {type, title, url, hint}.
     *
     * @return list<array{type: string, title: string, url: string, hint: ?string}>
     */
    public function build(): array
    {
        $items = [];

        // Курсы — витринные лендинги (карточки главной ссылаются на /{slug}).
        LandingPage::query()
            ->where('is_active', true)
            ->where('is_listed', true)
            ->orderBy('title')
            ->get(['title', 'slug'])
            ->each(function (LandingPage $landing) use (&$items): void {
                if (blank($landing->title) || blank($landing->slug)) {
                    return;
                }

                $items[] = [
                    'type' => 'курс',
                    'title' => (string) $landing->title,
                    'url' => '/'.$landing->slug,
                    'hint' => null,
                ];
            });

        // Курсы — публичные страницы /k/{slug}. Записи-дубли (H3807 «одна
        // карточка на программу») не продвигаются поиском: канонICAL — живой курс.
        Course::query()
            ->where('is_visible', true)
            ->whereNull('recording_of_course_id')
            ->with('teacher:id,name')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'teacher_id'])
            ->each(function (Course $course) use (&$items): void {
                if (blank($course->title) || blank($course->slug)) {
                    return;
                }

                $items[] = [
                    'type' => 'курс',
                    'title' => (string) $course->title,
                    'url' => '/k/'.$course->slug,
                    'hint' => $course->teacher?->name,
                ];
            });

        // Преподаватели с публичной страницей /prepodavately/{page_slug}.
        Teacher::query()
            ->withPublicPage()
            ->orderBy('name')
            ->get(['name', 'page_slug', 'page_role'])
            ->each(function (Teacher $teacher) use (&$items): void {
                if (blank($teacher->name) || blank($teacher->page_slug)) {
                    return;
                }

                $items[] = [
                    'type' => 'преподаватель',
                    'title' => (string) $teacher->name,
                    'url' => '/prepodavately/'.$teacher->page_slug,
                    'hint' => $teacher->page_role,
                ];
            });

        // Статьи блога — только опубликованные (scopePublished: дата настала).
        Article::query()
            ->published()
            ->with('category:id,title')
            ->orderBy('title')
            ->get(['id', 'category_id', 'slug', 'title', 'subtitle'])
            ->each(function (Article $article) use (&$items): void {
                if (blank($article->title) || blank($article->slug)) {
                    return;
                }

                $items[] = [
                    'type' => 'статья',
                    'title' => (string) $article->title,
                    'url' => '/s/'.$article->slug,
                    'hint' => $article->category?->title ?: ($article->subtitle ?: null),
                ];
            });

        return $items;
    }
}
