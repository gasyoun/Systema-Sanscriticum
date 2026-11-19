<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Pages\VisitorsOnline;
use App\Models\SupportVisitorPresence;

/**
 * Гейт проактивных приглашений (H6601): куратор пишет первым молчащему
 * посетителю ТОЛЬКО когда тот сидит на горячей странице (лендинг мини-курса,
 * витрина, оплата) и только в рабочие часы. Клиентская часть — кнопка
 * «Написать» на «Посетителях онлайн» прячется; серверная часть —
 * {@see VisitorsOnline::sendProactive()} отказывает
 * fail-closed по той же логике (UI не доверие, а удобство).
 *
 * Список горячих путей — config('support_presence.proactive.hot_paths'),
 * env SUPPORT_PROACTIVE_HOT_PATHS (запятая). Пустой список = проактив
 * выключен целиком (откат одной переменной). Рабочие часы — общий
 * {@see SupportAvailability::isOnline()} из config/support_hours.php; когда
 * расписание выключено, считается «всегда в часах» — решает человек в .env.
 */
final class SupportProactiveGate
{
    /** @return list<string> нормализованные префиксы горячих путей */
    public static function hotPaths(): array
    {
        $paths = (array) config('support_presence.proactive.hot_paths', []);

        return array_values(array_filter(array_map(
            static fn (string $p): string => '/'.ltrim(trim($p), '/'),
            $paths,
        )));
    }

    /** Горячая ли у посетителя страница сейчас (path URL, домен/query не важны). */
    public static function pageMatches(?string $url): bool
    {
        $hot = self::hotPaths();
        if ($hot === []) {
            return false;
        }

        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        $path = is_string($path) ? '/'.ltrim($path, '/') : '/';

        foreach ($hot as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix.'?')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Почему проактив этому посетителю сейчас нельзя; null = можно.
     * Порядок проверок — от решаемых человеком (часы) к страницам.
     */
    public static function refusalReason(SupportVisitorPresence $presence): ?string
    {
        if (self::hotPaths() === []) {
            return 'Проактив выключен (список горячих страниц пуст)';
        }

        if (! SupportAvailability::isOnline()) {
            return 'Вне рабочих часов — проактив только в рабочее время';
        }

        if (! self::pageMatches($presence->current_url)) {
            return 'Посетитель не на горячей странице';
        }

        return null;
    }

    /** Можно ли писать этому посетителю первым прямо сейчас. */
    public static function allows(SupportVisitorPresence $presence): bool
    {
        return self::refusalReason($presence) === null;
    }
}
