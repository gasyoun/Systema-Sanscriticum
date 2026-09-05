<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H4118 (05-09-2026): runtime Play CDN удалён — витрина, чекаут, кабинет и
 * авторизация получают тот же self-hosted бандл resources/css/app.css, что и
 * остальные страницы (partials/tailwind-cdn.blade.php теперь @vite-подключение).
 *
 * История: cdn.tailwindcss.com (v3.4.17) отдавал сломанный in-browser JIT без
 * `hidden`/`sm:*`/`md:*` (десктоп на iPhone, ox=646, карточка /login с width:0),
 * а раньше — H2560 (68f80829) — ещё и молчал про @theme-токены: кнопка оплаты
 * осталась без фона. Эти три красные линии держат регресс:
 *
 *  1. cdn.tailwindcss.com запрещён ВЕЗДЕ в resources/views;
 *  2. партиал отдаёт скомпилированный app.css через @vite;
 *  3. @theme-токены бренда в app.css не вымирают (на них держится ~1100 классов).
 */
class BrandTokenParityTest extends TestCase
{
    private const PARTIAL = 'resources/views/partials/tailwind-cdn.blade.php';

    private const CDN_HOST = 'cdn.tailwindcss.com';

    /** @test */
    public function the_play_cdn_is_banned_from_every_blade_view(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains(File::get($file->getPathname()), self::CDN_HOST)) {
                $offenders[] = 'resources/views/'.str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Play CDN снова подключён в представлениях — H4118 выпилил его как root-cause (сломанный JIT v3.4.17):\n"
            .implode("\n", $offenders)."\nПодключайте compiled CSS через @include('partials.tailwind-cdn')."
        );
    }

    /** @test */
    public function the_token_partial_serves_the_compiled_stylesheet_via_vite(): void
    {
        $partial = preg_replace('/\{\{--.*?--\}\}/s', '', File::get(base_path(self::PARTIAL)));

        $this->assertMatchesRegularExpression(
            '/@vite\s*\(\s*[\'"]resources\/css\/app\.css[\'"]\s*\)/',
            $partial,
            self::PARTIAL.' должен отдавать скомпилированный resources/css/app.css через @vite — '
            .'иначе страницы витрины/кабинета останутся без Tailwind-утилит и бренд-токенов.'
        );
    }

    /** @test */
    public function brand_theme_tokens_stay_declared_in_app_css(): void
    {
        $tokens = $this->themeColorTokens();

        $this->assertArrayHasKey('brand', $tokens, 'В @theme-блоке app.css пропал --color-brand — на нём держится ~1100 классов brand/*.');
        $this->assertArrayHasKey('brand-hover', $tokens, 'В @theme-блоке app.css пропал --color-brand-hover (H2560).');
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
