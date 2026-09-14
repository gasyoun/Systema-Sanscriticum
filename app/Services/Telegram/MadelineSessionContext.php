<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Console\Concerns\LocksMadelineSession;
use Illuminate\Support\Facades\Config;

/**
 * Контекст «какая MadelineProto-сессия открыта в этом процессе» (H3380).
 *
 * До H3380 сессия была ровно одна (D1, DECISIONS_telegram_harvester.md): путь
 * читался из конфига статикой {@see MadelineClientFactory::sessionPath()}, а
 * замок и фазы были глобальными ключами кеша. Второй аккаунт (rusamskrtam)
 * ломает это допущение, но НЕ ломает главный инвариант D1: два процесса
 * НИКОГДА не открывают одну сессию одновременно — замок остаётся, просто
 * становится пер-сессийным.
 *
 * Механизм точечный и обратимый: команда telegram-support:sync --account=X
 * вызывает useSession() ДО открытия клиента; дальше всё, что читает путь в
 * момент вызова ({@see MadelineClientFactory}, {@see MadelineSessionReaper},
 * {@see MadelineDaemonSupervisor}), автоматически видит нужную сессию, потому
 * что читает конфиг без кеширования. Для дефолтного пути ключи замка и фаз
 * НЕ меняются ('madeline-session', legacy) — существующая сериализация
 * support-sync ↔ harvest-sync сохраняется байт-в-байт.
 */
final class MadelineSessionContext
{
    private static ?string $overridePath = null;

    /**
     * Переключить этот процесс на другую сессию. Вызывать один раз, в начале
     * захода команды, до любого открытия клиента/замка.
     */
    public static function useSession(string $absolutePath): void
    {
        self::$overridePath = $absolutePath;
        Config::set('services.telegram_support.session', $absolutePath);
    }

    /**
     * Путь сессии из текущего конфига. После useSession() возвращает
     * переопределённый путь — это зеркало {@see MadelineClientFactory::sessionPath()},
     * а не «исходное значение окружения».
     */
    public static function defaultPath(): string
    {
        return MadelineClientFactory::sessionPath();
    }

    /** Активная сессия этого процесса (фактически — текущий конфиг). */
    public static function activePath(): string
    {
        return self::$overridePath ?? self::defaultPath();
    }

    /** Работаем ли мы на легаси-сессии по умолчанию (ключи кеша без суффикса). */
    public static function isDefault(): bool
    {
        return self::$overridePath === null;
    }

    /**
     * Имя cache-lock для активной сессии. Легаси-имя сознательно сохранено для
     * дефолта: им сериализуются telegram-support:sync и telegram-harvest:*,
     * открывающие ОДНУ сессию из разных команд ({@see LocksMadelineSession}).
     */
    public static function lockName(): string
    {
        return self::isDefault()
            ? 'madeline-session'
            : 'madeline-session-'.md5(self::$overridePath ?? '');
    }

    /** Суффикс ключей фаз/cooldown, чтобы таймаут одного аккаунта не глушил другой. */
    public static function phaseSuffix(): string
    {
        return self::isDefault() ? '' : '-'.md5(self::$overridePath ?? '');
    }
}
