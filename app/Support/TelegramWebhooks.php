<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Единая точка правды о том, КУДА регистрируются вебхуки Telegram.
 *
 * До 27.07.2026 её не было: каждая команда строила URL из `app.url`, а вебхук
 * кабинетного бота вообще ставился руками через curl. Из-за этого переезд
 * входного узла (Telegram → зарубежный nginx → обратный SSH-туннель → прод)
 * потерял @zapisi_ORSbot на пять дней — перерегистрировали только того бота, про
 * которого вспомнили.
 */
final class TelegramWebhooks
{
    /** База URL входного узла; пусто в конфиге → обычный app.url. */
    public static function baseUrl(): string
    {
        $configured = (string) config('services.telegram_webhook.base_url', '');

        // `?:`, а не второй аргумент config(): пустая строка в .env (KEY=) иначе
        // прошла бы как валидное значение и вебхуки уехали бы в никуда.
        return rtrim($configured ?: (string) config('app.url'), '/');
    }

    public static function url(string $path): string
    {
        return self::baseUrl().'/'.ltrim($path, '/');
    }

    /** Задан ли отдельный входной узел (в отличие от «Telegram стучится прямо к нам»). */
    public static function usesEntryNode(): bool
    {
        return (string) config('services.telegram_webhook.base_url', '') !== '';
    }

    /**
     * Содержимое сертификата входного узла — или null, если грузить нечего.
     *
     * Инвариант: сертификат принадлежит ИМЕННО входному узлу. Если узел не задан
     * и вебхук регистрируется на app.url с нормальным публичным сертификатом,
     * приложенный самоподписанный файл сломал бы доставку — поэтому здесь null,
     * даже когда путь в конфиге указан.
     *
     * Нечитаемый файл при заданном узле — громкая ошибка: тихая регистрация без
     * сертификата даёт живой вебхук, который молча ничего не доставляет, а это
     * ровно тот класс отказа, ради которого всё и затевалось.
     */
    public static function certificateContents(): ?string
    {
        $path = (string) config('services.telegram_webhook.certificate', '');

        if ($path === '' || ! self::usesEntryNode()) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException(
                "Сертификат входного узла недоступен: {$path}. Проверьте путь и права "
                .'(artisan работает не под root — файл должен читаться пользователем php).'
            );
        }

        return $contents;
    }
}
