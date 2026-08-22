<?php

declare(strict_types=1);

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

/**
 * H3309 — render-side whitelist-санитайзер для HTML, написанного персоналом
 * в Filament RichEditor'ах (анонсы студентам, био преподавателей, блоки
 * лендингов, email-рассылки). Доверять вводу нельзя: скомпрометированный или
 * злонамеренный staff-аккаунт иначе получает persistent XSS на публичных
 * страницах ({!! !!} без фильтра).
 *
 * Без внешних зависимостей (DOMDocument): неразрешённые теги разворачиваются
 * с сохранением текста, script/style/comments удаляются целиком, у
 * разрешённых тегов остаются только разрешённые атрибуты, а URL-атрибуты
 * проходят схемный фильтр (http/https/mailto/tel/относительные; data:/javascript:
 * и иные схемы вырезаются). on*-атрибуты невозможны by construction.
 */
final class SanitizedHtml
{
    /** @var array<string, bool> */
    private const ALLOWED_TAGS = [
        'p' => true, 'br' => true, 'hr' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'strong' => true, 'b' => true, 'em' => true, 'i' => true, 'u' => true,
        's' => true, 'del' => true, 'sup' => true, 'sub' => true, 'small' => true, 'mark' => true,
        'code' => true, 'pre' => true, 'blockquote' => true, 'span' => true, 'div' => true,
        'ul' => true, 'ol' => true, 'li' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true, 'th' => true, 'td' => true,
        'a' => true, 'img' => true, 'figure' => true, 'figcaption' => true,
    ];

    /**
     * Общие для всех тегов атрибуты + специфичные по тегам.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRS = [
        '*' => ['class'],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    public static function render(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;

        // Префикс заставляет XML-парсер читать вход как UTF-8 (HTML-ENTITIES убраны в PHP 8.1).
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><!DOCTYPE html><body>'.mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFF], 'UTF-8').'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        self::clean($body);
        self::addRelNoopener($body);

        $out = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            if ($child instanceof DOMComment || ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['script', 'style', 'iframe', 'object', 'embed', 'form'], true))) {
                $node->removeChild($child);

                continue;
            }

            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if (! isset(self::ALLOWED_TAGS[strtolower($child->tagName)])) {
                // Разворачиваем неизвестный тег: дети остаются, оболочка уходит.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            self::filterAttributes($child);
            self::clean($child);
        }
    }

    private static function filterAttributes(DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        $allowed = array_flip([...self::ALLOWED_ATTRS['*'], ...self::ALLOWED_ATTRS[$tag] ?? []]);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);

            if (! isset($allowed[$name]) || str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->name);

                continue;
            }

            if (in_array($name, ['href', 'src'], true)) {
                $value = trim($attr->value);

                if (! self::isSafeUrl($value)) {
                    $el->removeAttribute($attr->name);
                }
            }
        }
    }

    public static function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Относительные пути, якоря, query — безопасны.
        if (preg_match('#^(/|\#|\?|\./)#i', $url) === 1 || preg_match('#^(https?:|mailto:|tel:)#i', $url) === 1) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && ! str_contains(strtolower($url), 'javascript:');
    }

    private static function addRelNoopener(DOMNode $root): void
    {
        $links = $root->ownerDocument?->getElementsByTagName('a') ?? [];

        try {
            foreach ($links as $link) {
                if (strtolower($link->getAttribute('target')) === '_blank') {
                    $link->setAttribute('rel', 'noopener noreferrer');
                }
            }
        } catch (Throwable) {
            // never block rendering on hardening
        }
    }
}
