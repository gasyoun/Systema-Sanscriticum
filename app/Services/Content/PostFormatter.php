<?php

declare(strict_types=1);

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Текст слота календаря (HTML из TiptapEditor) → форматы площадок.
 *
 * Telegram понимает в parse_mode=HTML только узкий набор тегов, поэтому
 * редакторский HTML разбирается DOM-обходом и собирается заново: абзацы и
 * <br> → переносы строк, списки → «• »/«1. », заголовки → жирный, <mark>
 * (кнопка «маркер» в редакторе) → <tg-spoiler>. Стена VK разметки не умеет
 * совсем — туда уходит плоский текст, ссылка как «текст (url)», тот же приём,
 * что в TelegramFormatter::toPlain().
 *
 * Слоты, заполненные сидерами до редактора, хранят простой текст без тегов:
 * он уходит как есть (для TG — с экранированием & < >).
 */
final class PostFormatter
{
    /** Лимит подписи к фото/видео в Bot API (символы видимого текста). */
    public const TG_CAPTION_LIMIT = 1024;

    /** Лимит текста сообщения в Bot API. */
    public const TG_MESSAGE_LIMIT = 4096;

    private const MODE_TG = 'tg';

    private const MODE_PLAIN = 'plain';

    /** Как plain, но ссылки — только текст анкера: так длину считает Telegram. */
    private const MODE_TG_VISIBLE = 'tg_visible';

    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'tg', 'mailto'];

    public static function toTelegramHtml(?string $html): string
    {
        $html = (string) $html;
        if (! self::hasMarkup($html)) {
            return trim(htmlspecialchars($html, ENT_NOQUOTES, 'UTF-8'));
        }

        return self::render($html, self::MODE_TG);
    }

    public static function toPlain(?string $html): string
    {
        $html = (string) $html;
        if (! self::hasMarkup($html)) {
            return trim($html);
        }

        return self::render($html, self::MODE_PLAIN);
    }

    /** Длина видимого в Telegram текста в UTF-16 code units — так считает Bot API. */
    public static function telegramLength(?string $html): int
    {
        $html = (string) $html;
        $visible = self::hasMarkup($html) ? self::render($html, self::MODE_TG_VISIBLE) : trim($html);

        return intdiv(strlen((string) mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Простой текст старых слотов → абзацы для редактора: иначе Tiptap при
     * открытии склеит переносы строк в пробелы. HTML возвращается как есть.
     */
    public static function toEditorHtml(?string $text): ?string
    {
        if ($text === null || $text === '' || self::hasMarkup($text)) {
            return $text;
        }

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [$text];

        return implode('', array_map(
            static fn (string $line): string => '<p>'.htmlspecialchars($line, ENT_NOQUOTES, 'UTF-8').'</p>',
            $lines,
        ));
    }

    private static function hasMarkup(string $text): bool
    {
        return preg_match('#</?[a-z][a-z0-9-]*(\s[^<>]*)?/?>#i', $text) === 1;
    }

    private static function render(string $html, string $mode): string
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($doc->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $root = $child;
                break;
            }
        }
        if ($root === null) {
            return '';
        }

        $out = self::children($root, $mode);

        // Пустые абзацы редактора = пустая строка; больше одной подряд не нужно.
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;

        return trim($out);
    }

    private static function children(DOMNode $node, string $mode, bool $inPre = false): string
    {
        $out = '';
        $index = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $index++;
            }
            $out .= self::node($child, $mode, $inPre, $index);
        }

        return $out;
    }

    private static function node(DOMNode $node, string $mode, bool $inPre, int $liIndex): string
    {
        if ($node instanceof DOMText) {
            $text = $node->wholeText;

            return $mode === self::MODE_TG ? htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8') : $text;
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $tg = $mode === self::MODE_TG;

        if ($inPre) {
            return $tag === 'br' ? "\n" : self::children($node, $mode, true);
        }

        $inner = fn (): string => self::children($node, $mode);
        $wrap = fn (string $t): string => $tg ? "<{$t}>".$inner()."</{$t}>" : $inner();

        return match ($tag) {
            'b', 'strong' => $wrap('b'),
            'i', 'em' => $wrap('i'),
            'u', 'ins' => $wrap('u'),
            's', 'del', 'strike' => $wrap('s'),
            'mark' => $wrap('tg-spoiler'),
            'code' => $wrap('code'),
            'pre' => ($tg ? '<pre>'.self::children($node, $mode, true).'</pre>' : self::children($node, $mode, true))."\n",
            'br' => "\n",
            'p', 'div' => $inner()."\n",
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $wrap('b')."\n",
            'blockquote' => ($tg ? '<blockquote>'.rtrim($inner()).'</blockquote>' : rtrim($inner()))."\n",
            'ul', 'ol' => $inner(),
            'li' => (strtolower((string) $node->parentNode?->nodeName) === 'ol' ? $liIndex.'. ' : '• ')
                .rtrim($inner())."\n",
            'a' => self::link($node, $mode),
            'script', 'style' => '',
            default => $inner(),
        };
    }

    private static function link(DOMElement $node, string $mode): string
    {
        $text = self::children($node, $mode);
        $href = trim($node->getAttribute('href'));
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($href === '' || ! in_array($scheme, self::ALLOWED_LINK_SCHEMES, true)) {
            return $text;
        }

        return match ($mode) {
            self::MODE_TG => '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'">'.$text.'</a>',
            self::MODE_TG_VISIBLE => $text,
            default => trim($text) === '' || trim($text) === $href ? $href : $text.' ('.$href.')',
        };
    }
}
