<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Предохранитель (sentinel breaker) над циклом «зависание → watchdog-kill →
 * cooldown → снова зависание» MTProto-сессии. H4691, E002-C2 (вторая волна).
 *
 * Зачем. {@see MadelineSyncWatchdog} убивает зависший заход, {@see MadelineSyncPhase}
 * взводит 10-минутный cooldown — но кулдаун режет ЧАСТОТУ, не число кругов:
 * при стойком столле сессия проходит «kill → 10 мин → kill» до шести раз в час,
 * круглые сутки, и об этом никто не узнаёт (прод 16–17-08-2026: 18 kill'ов за
 * ~100 мин). Класс E002 — «защита, ставшая болезнью»: страж молча крутит петлю.
 *
 * Что делает. Каждый kill пишется в общий breaker-счётчик; перед следующим
 * заходом cooldown-гейт спрашивает breaker. Бюджет класса self_kill (3 kill'а
 * в час, 8 в сутки) исчерпан → breaker ЗАМОРАЖИВАЕТ заходы этой сессии на 2 ч и
 * КРИЧИТ (Telegram через `guards:breaker-alarm`), потом размораживается сам
 * с урезанным вдвое лимитом и кричит ещё раз. Короткая заморозка — потому что
 * синк пользовательский (сообщения студентов в поддержку): крик важнее паузы.
 *
 * Одна реализация. Бюджет, окна, заморозку и авто-разморозку считает общая
 * библиотека `tools/sentinel_breaker.py` из Uprava (развёрнута на .92 в
 * /usr/local/lib/sentinel-breaker/); здесь — только вызов её CLI, никакой
 * второй копии арифметики на PHP.
 *
 * Fail-open. Нет библиотеки или она упала — заходы идут под прежним cooldown'ом,
 * как до H4691: предохранитель не имеет права сам остановить поддержку. Kill без
 * библиотеки пишется в лог ГРОМКО (Log::error), чтобы тихой деградации не было.
 *
 * Горячий путь бесплатен: пока в state-каталоге сессии нет ни одного kill'а и
 * нет freeze.json, CLI не вызывается вовсе (здоровая минута = ноль подпроцессов).
 */
final class MadelineSyncBreaker
{
    public const GUARDIAN = 'madeline_sync';

    public const GUARDIAN_CLASS = 'self_kill';

    /** Kill зафиксирован (вызывается из обработчика таймаута, до exit()). */
    public static function recordKill(): void
    {
        if (! self::enabled()) {
            return;
        }
        if (! self::available()) {
            Log::error('MadelineSync watchdog-kill БЕЗ предохранителя: нет библиотеки sentinel_breaker (H4691).', [
                'bin' => self::bin(),
                'guardian' => self::guardian(),
            ]);

            return;
        }
        self::run(['record', self::guardian()]);
    }

    /**
     * true — breaker заморожен, заход запрещён. Любая ошибка вызова = false
     * (fail-open: заход решает прежний cooldown).
     */
    public static function frozen(): bool
    {
        if (! self::enabled() || ! self::available()) {
            return false;
        }
        $dir = self::stateDir().DIRECTORY_SEPARATOR.self::guardian();
        if (! is_file($dir.'/freeze.json') && ! self::hasActions($dir.'/actions.log')) {
            return false;
        }

        return self::run(['check', self::guardian(), '--label', self::label()]) === 1;
    }

    public static function guardian(): string
    {
        return self::GUARDIAN.MadelineSessionContext::phaseSuffix();
    }

    private static function label(): string
    {
        return 'MadelineSync .92 ('.self::guardian().')';
    }

    private static function enabled(): bool
    {
        return (bool) config('services.sentinel_breaker.enabled', true);
    }

    private static function available(): bool
    {
        return is_file(self::bin());
    }

    private static function bin(): string
    {
        return (string) config('services.sentinel_breaker.bin', '/usr/local/lib/sentinel-breaker/sentinel_breaker.py');
    }

    private static function stateDir(): string
    {
        return (string) config('services.sentinel_breaker.state_dir', storage_path('app/sentinel-breaker'));
    }

    private static function hasActions(string $path): bool
    {
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * @param  list<string>  $args
     * @return int|null exit code; null when the process could not run
     */
    private static function run(array $args): ?int
    {
        $cmd = array_merge(
            [(string) config('services.sentinel_breaker.python', 'python3'), self::bin()],
            $args,
            ['--class', self::GUARDIAN_CLASS, '--state-dir', self::stateDir()],
        );
        $env = [
            // The library appends "<label>" "<message>"; the command turns them
            // into a Telegram alert to the cabinet-probe critical chat.
            'SENTINEL_BREAKER_ALARM_CMD' => (string) config(
                'services.sentinel_breaker.alarm_cmd',
                escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' guards:breaker-alarm',
            ),
        ];
        try {
            $process = new Process($cmd, base_path(), $env, null, 60);
            $process->run();

            return $process->getExitCode();
        } catch (Throwable $e) {
            Log::warning('sentinel_breaker CLI failed — fail-open (cooldown only).', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return null;
        }
    }
}
