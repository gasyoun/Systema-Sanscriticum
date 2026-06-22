<?php

declare(strict_types=1);

namespace App\Services\Bot;

/**
 * Превращает ответ ИИ-куратора в чистый Telegram-HTML.
 *
 * Промпт просит модель форматировать в Telegram-HTML (<b>/<i>), но DeepSeek
 * регулярно срывается в Markdown — у студента всплывают сырые «###» и «**».
 * Полагаться на дисциплину модели нельзя, поэтому разметку нормализуем здесь
 * детерминированно: что бы ни прислала модель (Markdown, HTML или их смесь),
 * на выходе — валидный Telegram-HTML с поддерживаемым набором тегов.
 */
class TelegramFormatter
{
    /** Теги, которые Telegram реально понимает в parse_mode=HTML. */
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'del', 'code', 'pre', 'a', 'blockquote', 'tg-spoiler'];

    /**
     * Markdown/смешанный ответ → Telegram-HTML.
     */
    public static function toHtml(string $text): string
    {
        // 1. Вырезаем уже валидные теги модели в плейсхолдеры, чтобы не экранировать
        //    их на шаге 2 и не сломать конвертацией Markdown на шаге 3.
        $tags = [];
        $text = preg_replace_callback(
            '#</?(?:'.implode('|', self::ALLOWED_TAGS).')(?:\s[^<>]*)?>#i',
            function (array $m) use (&$tags): string {
                $key = "\x00TAG".count($tags)."\x00";
                $tags[$key] = $m[0];

                return $key;
            },
            $text
        ) ?? $text;

        // 2. Экранируем «голые» спецсимволы контента — иначе одиночный «<» или «&»
        //    роняет parse_mode=HTML (Telegram отвечает 400).
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        // 3. Конвертируем Markdown.
        $text = self::convertMarkdown($text);

        // 4. Возвращаем теги модели на место.
        return strtr($text, $tags);
    }

    /**
     * Telegram-HTML → плоский текст для каналов без разметки (ВК): сначала
     * нормализуем Markdown в теги, затем срезаем все теги. Так из ответа уходят
     * и «**»/«###», и голые <b> — остаются текст и эмодзи.
     */
    public static function toPlain(string $text): string
    {
        return trim(html_entity_decode(strip_tags(self::toHtml($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function convertMarkdown(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [$text];

        foreach ($lines as $i => $line) {
            // Заголовки «### …», «## …», «# …» → жирная строка без решёток.
            // Строка целиком жирная, поэтому внутренние **/__ убираем, чтобы не
            // плодить вложенный <b><b> (Telegram такого не любит).
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', $line, $m)) {
                $heading = str_replace(['**', '__'], '', rtrim($m[1], " #\t"));
                $line = '<b>'.$heading.'</b>';
            }

            // Маркеры списка «- » / «* » / «+ » в начале строки → «• ».
            $line = preg_replace('/^(\s*)[-*+]\s+/u', '$1• ', $line);

            $lines[$i] = $line;
        }

        $text = implode("\n", $lines);

        // Жирный: **текст** и __текст__ (до одиночных * / _).
        $text = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $text);
        $text = preg_replace('/__(.+?)__/su', '<b>$1</b>', $text);

        // Курсив: *текст* и _текст_ (одиночные, не затрагивая уже снятые двойные).
        $text = preg_replace('/(?<![\*\w])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\*\w])/su', '<i>$1</i>', $text);
        $text = preg_replace('/(?<![_\w])_(?!\s)([^_\n]+?)(?<!\s)_(?![_\w])/su', '<i>$1</i>', $text);

        // Инлайн-код: `текст` → <code>текст</code>.
        $text = preg_replace('/`([^`\n]+?)`/su', '<code>$1</code>', $text);

        // Ссылки [текст](url) → <a href="url">текст</a>.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            fn (array $m): string => '<a href="'.$m[2].'">'.$m[1].'</a>',
            $text
        ) ?? $text;

        return $text;
    }
}
