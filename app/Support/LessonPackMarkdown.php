<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H3521 — минимальный безопасный рендер персонализированного текста пака.
 *
 * Волна 1 сознательно без markdown-зависимостей: экранируем всё, затем
 * применяем узкое подмножество разметки генератора (# заголовки, > цитаты,
 * - списки, **жирный**, *курсив*, --- линия). Любой другой синтаксис
 * остаётся дословным текстом — инъекция невозможна.
 */
class LessonPackMarkdown
{
    public static function toHtml(string $text): string
    {
        $lines = explode("\n", htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false));
        $html = [];
        $list = null; // 'ul' | 'ol' | null

        $closeList = function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = "</{$list}>";
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $t = trim($line);

            if ($t === '') {
                $closeList();

                continue;
            }
            if ($t === '---') {
                $closeList();
                $html[] = '<hr>';

                continue;
            }
            if (str_starts_with($t, '### ')) {
                $closeList();
                $html[] = '<h3>'.self::inline(substr($t, 4)).'</h3>';

                continue;
            }
            if (str_starts_with($t, '## ') || str_starts_with($t, '# ')) {
                $closeList();
                $head = substr($t, strpos($t, ' ') + 1);
                $html[] = '<h2>'.self::inline($head).'</h2>';

                continue;
            }
            if (str_starts_with($t, '&gt; ')) {
                // '>' после экранирования
                $closeList();
                $html[] = '<blockquote>'.self::inline(substr($t, 5)).'</blockquote>';

                continue;
            }
            if (str_starts_with($t, '- ')) {
                if ($list !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>'.self::inline(substr($t, 2)).'</li>';

                continue;
            }
            if (preg_match('/^\d+\.\s+(.*)$/', $t, $m) === 1) {
                if ($list !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $list = 'ol';
                }
                $html[] = '<li>'.self::inline($m[1]).'</li>';

                continue;
            }

            $closeList();
            $html[] = '<p>'.self::inline($t).'</p>';
        }
        $closeList();

        return implode("\n", $html);
    }

    private static function inline(string $s): string
    {
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s) ?? $s;

        return $s;
    }
}
