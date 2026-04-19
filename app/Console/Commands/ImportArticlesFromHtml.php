<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

class ImportArticlesFromHtml extends Command
{
    /**
     * Сигнатура:
     *   php artisan articles:import                  — импорт из public/articles/, без удаления
     *   php artisan articles:import --delete         — после успешного импорта удалить исходник
     *   php artisan articles:import --path=foo.html  — импорт одного файла по пути
     *   php artisan articles:import --force          — перезаписать существующие статьи
     */
    protected $signature = 'articles:import
                            {--path= : Путь к одному файлу (относительно public/ или абсолютный)}
                            {--force : Перезаписать существующие статьи (по slug)}
                            {--delete : Удалить исходный HTML после успешного импорта}';

    protected $description = 'Импорт статей из готовых HTML-файлов в БД';

    public function handle(): int
    {
        $files = $this->collectFiles();

        if (empty($files)) {
            $this->warn('Не найдено HTML-файлов для импорта.');
            return self::SUCCESS;
        }

        $this->info('Найдено файлов: ' . count($files));
        $this->newLine();

        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($files as $file) {
            $this->line("→ Обрабатываю: <fg=cyan>{$file}</>");

            try {
                $result = $this->importFile($file);

                match ($result) {
                    'created'   => $imported++,
                    'updated'   => $imported++,
                    'skipped'   => $skipped++,
                };
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ✗ Ошибка: {$e->getMessage()}");
                continue;
            }

            // Удаляем исходник, если флаг --delete
            if ($this->option('delete')) {
                if (@unlink($file)) {
                    $this->line("  <fg=gray>✓ Исходник удалён</>");
                } else {
                    $this->warn("  ⚠ Не удалось удалить: {$file}");
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("Готово: импортировано {$imported}, пропущено {$skipped}, ошибок {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Собираем список файлов для импорта.
     */
    private function collectFiles(): array
    {
        if ($path = $this->option('path')) {
            // Если путь относительный — считаем от public/
            $absolute = str_starts_with($path, '/') ? $path : public_path($path);

            if (!is_file($absolute)) {
                $this->error("Файл не найден: {$absolute}");
                return [];
            }

            return [$absolute];
        }

        // По умолчанию — все .html в public/articles/
        $dir = public_path('articles');

        if (!is_dir($dir)) {
            $this->error("Папка не найдена: {$dir}");
            return [];
        }

        return glob($dir . '/*.html') ?: [];
    }

    /**
     * Парсинг одного файла и сохранение в БД.
     *
     * @return string 'created' | 'updated' | 'skipped'
     */
    private function importFile(string $path): string
    {
        $html = file_get_contents($path);

        if ($html === false || $html === '') {
            throw new \RuntimeException('Файл пустой или нечитаемый');
        }

        $slug = pathinfo($path, PATHINFO_FILENAME);
        $existing = Article::where('slug', $slug)->first();

        if ($existing && !$this->option('force')) {
            $this->warn("  ⚠ Статья со slug '{$slug}' уже существует. Используйте --force для перезаписи.");
            return 'skipped';
        }

        $crawler = new Crawler($html);
        $data = $this->extract($crawler, $slug);

        if ($existing) {
            $existing->update($data);
            $this->info("  ✓ Обновлена: <fg=yellow>{$data['title']}</>");
            return 'updated';
        }

        Article::create($data);
        $this->info("  ✓ Создана: <fg=green>{$data['title']}</>");
        return 'created';
    }

    /**
     * Извлечение всех нужных данных из HTML.
     */
    private function extract(Crawler $crawler, string $slug): array
    {
        // ── META: title и description из <head> ──
        $metaTitle = $this->safeText($crawler, 'head title');
        $metaDescription = $this->safeAttr($crawler, 'head meta[name="description"]', 'content');

        // ── HERO: заголовок, подзаголовок (em), лид, время чтения ──
        $heroTitleNode = $crawler->filter('.article-hero__title');

        if ($heroTitleNode->count() === 0) {
            throw new \RuntimeException('Не найден .article-hero__title — формат файла отличается от ожидаемого');
        }

        // Подзаголовок — содержимое <em> внутри H1
        $subtitle = null;
        $emNode = $heroTitleNode->filter('em');
        if ($emNode->count() > 0) {
            $subtitle = trim($emNode->text());
        }

        // Заголовок — текст H1 без <em> и <br>
        $title = $this->extractTitleWithoutEm($heroTitleNode, $subtitle);

        $excerpt = $this->safeText($crawler, '.article-hero__lead');

        // Время чтения: ищем "10 минут" в meta-блоке
        $readingTime = $this->extractReadingTime($crawler);

        // Автор — ищем span после иконки .fa-university
        $authorName = $this->extractAuthor($crawler) ?: 'Общество ревнителей санскрита';

        // ── BODY: содержимое <main class="article-body"> как HTML ──
        $bodyNode = $crawler->filter('main.article-body');
        if ($bodyNode->count() === 0) {
            throw new \RuntimeException('Не найден main.article-body — нечего импортировать');
        }
        $body = $this->innerHtml($bodyNode);

        return [
            'slug'             => $slug,
            'title'            => $title,
            'subtitle'         => $subtitle,
            'excerpt'          => $excerpt,
            'body'             => $body,
            'reading_time'     => $readingTime,
            'author_name'      => $authorName,
            'meta_title'       => $metaTitle,
            'meta_description' => $metaDescription,
            'is_published'     => true,
            'published_at'     => now(),
            // category_id, cover_path — заполняются вручную в админке
        ];
    }

    /**
     * Безопасное чтение текста селектора. Если не найден — null.
     */
    private function safeText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);
        return $node->count() > 0 ? trim($node->text()) : null;
    }

    /**
     * Безопасное чтение атрибута.
     */
    private function safeAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        $node = $crawler->filter($selector);
        return $node->count() > 0 ? $node->attr($attr) : null;
    }

    /**
     * Заголовок без вложенного <em> и без лишних пробелов от <br>.
     * Пример: "Санскрит для взрослого мозга:" (без "зачем, как и с чего начать")
     */
    private function extractTitleWithoutEm(Crawler $heroTitle, ?string $subtitle): string
    {
        $full = trim($heroTitle->text(''));

        // Убираем подзаголовок из полного текста, если он там был
        if ($subtitle && str_contains($full, $subtitle)) {
            $full = trim(str_replace($subtitle, '', $full));
        }

        // Чистим лишние пробелы и переводы строк
        $full = preg_replace('/\s+/u', ' ', $full);

        return trim($full);
    }

    /**
     * Время чтения. Ищем число перед словом "минут" в hero-meta.
     */
    private function extractReadingTime(Crawler $crawler): int
    {
        $metaText = $this->safeText($crawler, '.article-hero__meta');

        if ($metaText && preg_match('/(\d+)\s*мин/u', $metaText, $matches)) {
            return (int) $matches[1];
        }

        return 5; // дефолт
    }

    /**
     * Имя автора. По нашей разметке — span с иконкой .fa-university.
     */
    private function extractAuthor(Crawler $crawler): ?string
    {
        $items = $crawler->filter('.article-hero__meta-item');

        foreach ($items as $node) {
            $text = trim($node->textContent);
            // Если в тексте есть слово "санскрит" / "общество" / "автор" — берём
            if (preg_match('/общество|автор/iu', $text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Внутренний HTML узла (всё содержимое, без самого тега).
     * Symfony Crawler не имеет нативного innerHTML — собираем через DOMDocument.
     */
    private function innerHtml(Crawler $node): string
    {
        $domNode = $node->getNode(0);
        if (!$domNode) {
            return '';
        }

        $html = '';
        foreach ($domNode->childNodes as $child) {
            $html .= $domNode->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }
}