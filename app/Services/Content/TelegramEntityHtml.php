<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Текст поста Telegram + entities (смещения в UTF-16) → HTML редактора
 * календаря (теги TiptapEditor: strong/em/u/s/mark/code/pre/a/blockquote,
 * переносы строк — <br> внутри одного абзаца). Нужен, чтобы пост, вышедший
 * в канале, был виден и правился в админке и уходил плоским текстом в VK
 * (PostFormatter::toPlain). В сами TG-каналы такой пост копируется
 * copyMessage — оригинальная разметка не пересобирается.
 */
final class TelegramEntityHtml
{
    private const TAGS = [
        'bold' => ['<strong>', '</strong>'],
        'italic' => ['<em>', '</em>'],
        'underline' => ['<u>', '</u>'],
        'strikethrough' => ['<s>', '</s>'],
        'spoiler' => ['<mark>', '</mark>'],
        'code' => ['<code>', '</code>'],
        'pre' => ['<pre>', '</pre>'],
        'blockquote' => ['<blockquote>', '</blockquote>'],
        'expandable_blockquote' => ['<blockquote>', '</blockquote>'],
    ];

    /** @param  list<array<string, mixed>>  $entities */
    public static function toEditorHtml(string $text, array $entities = []): string
    {
        if ($text === '') {
            return '';
        }

        $units = self::utf16Units($text);
        $count = count($units);

        /** @var array<int, list<string>> $open */
        $open = [];
        /** @var array<int, list<string>> $close */
        $close = [];

        // Внешние сущности открываются раньше и закрываются позже вложенных.
        usort($entities, static fn (array $a, array $b): int => [(int) $a['offset'], -(int) $a['length']] <=> [(int) $b['offset'], -(int) $b['length']]);

        foreach ($entities as $entity) {
            $type = (string) ($entity['type'] ?? '');
            $start = (int) ($entity['offset'] ?? 0);
            $end = $start + (int) ($entity['length'] ?? 0);
            if ($end <= $start || $start < 0 || $end > $count) {
                continue;
            }

            if ($type === 'text_link' && is_string($entity['url'] ?? null)) {
                $pair = ['<a href="'.htmlspecialchars($entity['url'], ENT_QUOTES, 'UTF-8').'">', '</a>'];
            } elseif (isset(self::TAGS[$type])) {
                $pair = self::TAGS[$type];
            } else {
                continue; // url, mention, hashtag… — остаются текстом
            }

            $open[$start][] = $pair[0];
            $close[$end] ??= [];
            array_unshift($close[$end], $pair[1]);
        }

        $html = '';
        $buffer = [];
        for ($i = 0; $i <= $count; $i++) {
            if (isset($close[$i]) || isset($open[$i]) || $i === $count) {
                $html .= self::flush($buffer);
                $buffer = [];
                $html .= implode('', $close[$i] ?? []).implode('', $open[$i] ?? []);
            }
            if ($i < $count) {
                $buffer[] = $units[$i];
            }
        }

        return '<p>'.str_replace("\n", '<br>', $html).'</p>';
    }

    /** @return list<string> UTF-16 code units, each as a 2-byte string */
    private static function utf16Units(string $text): array
    {
        return str_split((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 2);
    }

    /** @param  list<string>  $units */
    private static function flush(array $units): string
    {
        if ($units === []) {
            return '';
        }

        return htmlspecialchars((string) mb_convert_encoding(implode('', $units), 'UTF-8', 'UTF-16LE'), ENT_NOQUOTES, 'UTF-8');
    }
}
