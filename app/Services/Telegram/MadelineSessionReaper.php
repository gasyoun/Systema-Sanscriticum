<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Console\Concerns\LocksMadelineSession;

/**
 * Гасит фоновые процессы MadelineProto ОДНОЙ конкретной сессии и сносит её
 * осиротевшие IPC-артефакты. Программный аналог ручного ранбука прода
 * (`pkill -f <session> && rm <session>/*.ipc`).
 *
 * Почему по пути сессии, а не по слову «madeline». Прежняя реализация звала
 * `pgrep -f madeline` и била по всем демонам сразу. Пока экземпляр синка один,
 * разницы нет; 27.07.2026 на проде их оказалось десять (замок планировщика
 * протухал раньше, чем зависший заход умирал) — и каждый убивал демонов
 * остальных, а те немедленно поднимались заново. Самоподдерживающийся цикл
 * съел дескрипторы до EMFILE. Путь сессии присутствует в командной строке и
 * раннера (`entry.php madeline-ipc <session> <id>`), и воркера
 * (`MadelineProto worker <session>`), поэтому фильтр по нему точен и не
 * задевает соседние сессии.
 *
 * Вызывать под общим замком сессии ({@see LocksMadelineSession}):
 * тогда «чужих» легальных демонов этой сессии в этот момент нет.
 */
class MadelineSessionReaper
{
    /**
     * SIGTERM всем фоновым процессам сессии, кроме собственного и родительского.
     * Только POSIX; без exec/posix (или на Windows) — тихий no-op, тогда
     * работает лишь чистка сокетов.
     *
     * @return int сколько процессов получили сигнал (для логов диагностики)
     */
    public function killDaemons(): int
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('exec') || ! function_exists('posix_kill')) {
            return 0;
        }

        $session = MadelineClientFactory::sessionPath();
        if ($session === '') {
            return 0;
        }

        $self = function_exists('getmypid') ? (int) getmypid() : 0;
        $parent = function_exists('posix_getppid') ? posix_getppid() : 0;

        $lines = [];
        @exec('pgrep -f -- '.escapeshellarg($this->pgrepPattern($session)).' 2>/dev/null', $lines);

        $killed = 0;
        foreach ($lines as $line) {
            $pid = (int) trim($line);
            if ($pid <= 0 || $pid === $self || $pid === $parent) {
                continue;
            }
            if (@posix_kill($pid, 15)) { // SIGTERM
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * Удаляет осиротевший IPC-сокет/состояние сессии, НЕ трогая учётные данные:
     * `safe.php` и прочие `*.php` лежат в том же каталоге, и их удаление
     * разлогинило бы аккаунт (нужен повторный интерактивный вход с кодом).
     * После чистки следующий `new API()->start()` поднимет свежий демон.
     *
     * @return array<int, string> реально удалённые файлы (для логов диагностики)
     */
    public function clearIpcArtifacts(): array
    {
        $session = MadelineClientFactory::sessionPath();

        // v8: сессия — это КАТАЛОГ (X.madeline) с IPC-артефактами внутри.
        if (! is_dir($session)) {
            return [];
        }

        $targets = [];
        foreach (['ipc', 'callback.ipc', 'ipcState.php'] as $name) {
            $targets[] = $session.DIRECTORY_SEPARATOR.$name;
        }
        foreach ((glob($session.DIRECTORY_SEPARATOR.'*.ipc') ?: []) as $path) {
            $targets[] = $path;
        }

        $removed = [];
        foreach (array_unique($targets) as $path) {
            if (is_file($path) && @unlink($path)) {
                $removed[] = basename($path);
            }
        }

        return $removed;
    }

    /**
     * Путь сессии как literal-паттерн для `pgrep -f`: тот трактует аргумент как
     * ERE, поэтому метасимволы (точка в `session.madeline`, дефисы в каталогах
     * роли не играют, а вот `.`/`+`/`(` — играют) экранируем.
     */
    private function pgrepPattern(string $session): string
    {
        return preg_replace('~([.\[\]()*+?^$|{}\\\\])~', '\\\\$1', $session) ?? $session;
    }
}
