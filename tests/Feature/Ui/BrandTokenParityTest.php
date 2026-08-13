<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Витрина, чекаут, кабинет и авторизация собирают Tailwind не из
 * resources/css/app.css, а с Play CDN, который читает только глобальный
 * `tailwind.config` и ничего не знает про `@theme`.
 *
 * H2560 (коммит 68f80829) завёл там токены --color-brand / --color-brand-hover
 * и заменил на них ~1100 классов [#E85C24] — на CDN-страницах эти утилиты
 * перестали существовать, и кнопка оплаты на чекауте осталась без фона.
 *
 * Тест держит две половины в паритете, пока CDN не уйдёт совсем.
 */
class BrandTokenParityTest extends TestCase
{
    private const PARTIAL = 'resources/views/partials/tailwind-cdn.blade.php';

    private const CDN_HOST = 'cdn.tailwindcss.com';

    /** @test */
    public function every_theme_token_from_app_css_is_mirrored_in_the_cdn_config(): void
    {
        $tokens = $this->themeColorTokens();
        $partial = File::get(base_path(self::PARTIAL));

        $this->assertNotEmpty($tokens, 'В @theme-блоке resources/css/app.css не нашлось ни одного --color-* токена.');

        foreach ($tokens as $name => $hex) {
            $this->assertMatchesRegularExpression(
                '/[\'"]?'.preg_quote($name, '/').'[\'"]?\s*:\s*[\'"]'.preg_quote($hex, '/').'[\'"]/i',
                $partial,
                "Токен --color-{$name} ({$hex}) объявлен в resources/css/app.css, но не отдан Play CDN в ".self::PARTIAL
                .'. На страницах витрины и кабинета утилиты этого цвета не сгенерируются вовсе.'
            );
        }
    }

    /** @test */
    public function the_cdn_is_loaded_only_through_the_token_partial(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (! str_contains(File::get($file->getPathname()), self::CDN_HOST)) {
                continue;
            }

            if ($relative !== 'partials/tailwind-cdn.blade.php') {
                $offenders[] = 'resources/views/'.$relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Play CDN подключён мимо '.self::PARTIAL." — эти страницы останутся без brand-токенов:\n"
            .implode("\n", $offenders)."\nПодключайте через @include('partials.tailwind-cdn')."
        );
    }

    /** @test */
    public function the_config_is_declared_after_the_cdn_script(): void
    {
        // Сам партиал начинается с blade-комментария, где оба имени тоже упомянуты,
        // поэтому сравниваем позиции уже в очищенной разметке.
        $partial = preg_replace('/\{\{--.*?--\}\}/s', '', File::get(base_path(self::PARTIAL)));

        $cdnAt = strpos($partial, self::CDN_HOST);
        preg_match('/tailwind\.config\s*=/', $partial, $m, PREG_OFFSET_CAPTURE);
        $configAt = $m[0][1] ?? null;

        $this->assertIsInt($cdnAt, 'В партиале нет тега Play CDN.');
        $this->assertIsInt($configAt, 'В партиале нет присваивания tailwind.config.');
        $this->assertGreaterThan(
            $cdnAt,
            $configAt,
            'tailwind.config должен присваиваться ПОСЛЕ загрузки CDN: до неё глобала `tailwind` ещё нет.'
        );
    }

    /**
     * @return array<string, string> имя токена без префикса --color- => hex
     */
    private function themeColorTokens(): array
    {
        $css = File::get(base_path('resources/css/app.css'));

        // Читаем только сам @theme-блок: выше него лежит длинный комментарий,
        // упоминающий и уже отложенные токены (--color-surface, --color-ink).
        if (! preg_match('/@theme\s*\{(.*?)\}/s', $css, $block)) {
            $this->fail('В resources/css/app.css не найден блок @theme.');
        }

        preg_match_all('/--color-([a-z0-9-]+)\s*:\s*(#[0-9a-f]{3,8})\s*;/i', $block[1], $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[strtolower($match[1])] = strtolower($match[2]);
        }

        return $tokens;
    }
}
