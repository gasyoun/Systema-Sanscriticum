<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ImpersonationAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Режим просмотра за пользователя (H1947) — единственный источник правды.
 *
 * Модель — ПОДМЕНА СЕССИИ, а не правка users.role: сессия логинится под целевым
 * пользователем, а id настоящего супер-админа кладётся в ключ сессии, чтобы был
 * обратный путь. Отсюда бесплатно берётся главное требование хэндоффа: под
 * куратором {@see RoleGate} и {@see User::isSuperAdmin()} видят РОВНО куратора —
 * обходить нечего, потому что супер-админа в сессии в этот момент нет. Правка
 * users.role такой гарантии не даёт (это не превью, а необратимое изменение
 * единственного супер-админа) и потому отвергнута.
 *
 * Что режим НЕ делает никогда: не входит под другого супер-админа, не вкладывается
 * сам в себя, не пишет по денежному контуру (см. {@see self::isBlockedWrite()}) и не
 * подделывает активность целевого пользователя — трекеры активности и слушатель
 * входа режим пропускают, иначе превью кабинета накрутило бы студенту login_count
 * и отправило ему онбординг-пинг «первый вход».
 */
final class Impersonation
{
    public const MODE_STUDENT = 'student';

    public const MODE_MANAGER = 'manager';

    /** id настоящего сотрудника, к которому возвращает «выйти из режима». */
    public const SESSION_IMPERSONATOR = 'impersonator_id';

    public const SESSION_MODE = 'impersonation_mode';

    public const SESSION_AUDIT = 'impersonation_audit_id';

    /** @return array<int,string> */
    public static function modes(): array
    {
        return [self::MODE_STUDENT, self::MODE_MANAGER];
    }

    public static function enabled(): bool
    {
        return (bool) config('features.staff_impersonation', false);
    }

    public static function isActive(): bool
    {
        return session()->has(self::SESSION_IMPERSONATOR);
    }

    public static function mode(): ?string
    {
        $mode = session()->get(self::SESSION_MODE);

        return in_array($mode, self::modes(), true) ? $mode : null;
    }

    public static function impersonator(): ?User
    {
        $id = session()->get(self::SESSION_IMPERSONATOR);

        return $id ? User::find($id) : null;
    }

    // ==========================================
    // КТО И ЗА КОГО МОЖЕТ ВОЙТИ
    // ==========================================

    /**
     * Начать режим может ТОЛЬКО настоящий супер-админ и только вне режима.
     * Обычный admin — нет: кнопка заводилась под «это я, владелец» (открытый
     * вопрос №3 хэндоффа), а расширение на admin молча раздало бы доступ к
     * чужим кабинетам всей админской роли.
     */
    public static function canStart(): bool
    {
        if (! self::enabled() || self::isActive()) {
            return false;
        }

        $actor = auth()->user();

        return $actor instanceof User && $actor->isSuperAdmin();
    }

    public static function canImpersonate(?User $target, string $mode): bool
    {
        if (! $target instanceof User || ! in_array($mode, self::modes(), true) || ! self::canStart()) {
            return false;
        }

        $actor = auth()->user();

        // Сам под себя и под другого супер-админа — никогда: второе отдало бы
        // полные права владельца тому, у кого их сейчас нет.
        if ($actor->is($target) || $target->isSuperAdmin()) {
            return false;
        }

        return $mode === self::MODE_STUDENT
            ? self::isStudentTarget($target)
            : $target->isManager();
    }

    /**
     * Студент — это пользователь БЕЗ штатной роли. Проверяем по списку ролей, а
     * не `role === null`: легаси-строка с мусорным значением роли не должна
     * пролезать в «студенческую» ветку. Легаси-флаг is_admin тоже отсекаем — он
     * решает, куда уводит /home.
     */
    public static function isStudentTarget(User $target): bool
    {
        return ! in_array($target->role, array_keys(Roles::all()), true)
            && ! $target->is_admin;
    }

    /** Подписанная одноразовая ссылка старта — она же защита от CSRF на GET. */
    public static function startUrl(User $target, string $mode): string
    {
        return URL::temporarySignedRoute('impersonate.start', now()->addMinutes(5), [
            'user' => $target->getKey(),
            'mode' => $mode,
        ]);
    }

    // ==========================================
    // СТАРТ / СТОП
    // ==========================================

    public static function start(User $target, string $mode, Request $request): ImpersonationAudit
    {
        /** @var User $actor */
        $actor = auth()->user();

        $audit = ImpersonationAudit::create([
            'impersonator_id' => $actor->getKey(),
            'impersonator_name' => $actor->name,
            'target_id' => $target->getKey(),
            'target_name' => $target->name,
            'mode' => $mode,
            'started_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);

        // Ключи ставим ДО Auth::login — событие Login разбирается слушателями
        // синхронно, и они должны увидеть, что это режим, а не настоящий вход
        // студента (иначе UserLoginListener отправит онбординг «первый вход»).
        // Данные сессии переживают login: он мигрирует ID, а не содержимое.
        $request->session()->put(self::SESSION_IMPERSONATOR, $actor->getKey());
        $request->session()->put(self::SESSION_MODE, $mode);
        $request->session()->put(self::SESSION_AUDIT, $audit->getKey());

        Auth::guard('web')->login($target);

        self::syncPasswordHash($request, $target);

        Log::info('impersonation.start', [
            'audit_id' => $audit->getKey(),
            'impersonator_id' => $actor->getKey(),
            'target_id' => $target->getKey(),
            'mode' => $mode,
        ]);

        return $audit;
    }

    /**
     * Выйти из режима. Возвращает восстановленного сотрудника либо null, если
     * возвращаться не к кому (сотрудника удалили) — тогда сессия гасится
     * целиком, а не оставляется под чужим пользователем.
     */
    public static function stop(Request $request): ?User
    {
        $impersonatorId = $request->session()->pull(self::SESSION_IMPERSONATOR);
        $mode = $request->session()->pull(self::SESSION_MODE);
        $auditId = $request->session()->pull(self::SESSION_AUDIT);

        if ($auditId) {
            ImpersonationAudit::whereKey($auditId)
                ->whereNull('stopped_at')
                ->update(['stopped_at' => now()]);
        }

        $impersonator = $impersonatorId ? User::find($impersonatorId) : null;

        Log::info('impersonation.stop', [
            'audit_id' => $auditId,
            'impersonator_id' => $impersonatorId,
            'mode' => $mode,
            'restored' => $impersonator instanceof User,
        ]);

        if (! $impersonator instanceof User) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($impersonator);
        self::syncPasswordHash($request, $impersonator);

        return $impersonator;
    }

    // ==========================================
    // ГРАНИЦЫ РЕЖИМА
    // ==========================================

    /**
     * Денежная запись/выдача доступа в режиме запрещена (решение по открытому
     * вопросу №1 хэндоффа — режим читающий). Списки — в config/impersonation.php.
     */
    public static function isBlockedWrite(Request $request): bool
    {
        $writeMethods = (array) config('impersonation.write_methods', []);

        if (! in_array($request->method(), $writeMethods, true)) {
            return false;
        }

        // Выход из режима запрещать нельзя — иначе из него не выбраться.
        $name = (string) ($request->route()?->getName() ?? '');

        if ($name === 'impersonate.stop') {
            return false;
        }

        foreach ((array) config('impersonation.blocked_route_prefixes', []) as $prefix) {
            if ($name !== '' && str_starts_with($name, (string) $prefix)) {
                return true;
            }
        }

        foreach ((array) config('impersonation.blocked_uri_prefixes', []) as $prefix) {
            if ($request->is($prefix, $prefix.'/*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * AuthenticateSession (стоит в middleware панели Filament) сверяет хэш пароля
     * из сессии с хэшем текущего пользователя и разлогинивает при расхождении.
     * После подмены пользователя хэш надо переставить руками, иначе вход под
     * куратором выбрасывало бы обратно на форму логина.
     */
    private static function syncPasswordHash(Request $request, User $user): void
    {
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $user->getAuthPassword()
        );
    }
}
