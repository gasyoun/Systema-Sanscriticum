<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Безопасный рендер тела FAQ-раздела для веб-страницы /dvaram/faq (H6301).
 *
 * Основа — общий {@see SupportText::safeHtml()} веб-чата (экранирование всего +
 * узкий whitelist тегов), плюс автоссылки на голые URL: корпус faq.md полон
 * важных ссылок (бланк возврата, samskrtam.ru/u, витрина), которые иначе
 * остаются некликабельным текстом.
 *
 * Порядок важен: safeHtml уже вернул экранированный HTML с whitelist-тегами,
 * поэтому в regex попадают только сущности (&amp; и т.п.), а не сырые < > " —
 * сломать атрибут href изнутри невозможно.
 */
final class FaqBodyHtml
{
    /** Автоссылка: http/https до первого пробела/тега, без хвостовой пунктуации. */
    private const URL_PATTERN = '/(https?:\/\/[^\s<>"\']+[^\s<>"\'.,;:!?)\]])/ui';

    public static function render(string $body): string
    {
        $html = SupportText::safeHtml($body);

        return (string) preg_replace_callback(
            self::URL_PATTERN,
            static function (array $m): string {
                $url = $m[1];

                return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>';
            },
            $html,
        );
    }
}
