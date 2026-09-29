<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Generic sentinel_breaker gate for an unattended MUTATOR of money rows
 * (access/grant/refund/payment) — H4930, E002 layer 1. Doctrine C3 names
 * «мутации платёжной БД» as a breaker target; the first guardian was
 * MadelineSyncBreaker (self_kill class, H4691) — this class extracts the
 * same call-into-Uprava's-single-implementation shape for ANY guardian
 * name/class instead of hardcoding one guardian, so a second money mutator
 * does not reimplement the CLI plumbing.
 *
 * Budget arithmetic lives ONLY in Uprava's tools/sentinel_breaker.py
 * (guardian class "money_mutation", H4930). Fail-open: no library, or the
 * CLI errors, and the caller proceeds as if the breaker did not exist —
 * a missing predohranitel must never itself block a real payment.
 */
final class SentinelBreakerGate
{
    public const CLASS_MONEY_MUTATION = 'money_mutation';

    /**
     * true = frozen, the caller MUST skip its mutation and log/alarm instead.
     * Any failure to run the CLI resolves to false (fail-open).
     */
    public static function frozen(string $guardian, string $guardianClass, string $label): bool
    {
        if (! self::enabled() || ! self::available()) {
            return false;
        }

        return self::run($guardian, $guardianClass, ['check', $guardian, '--label', $label]) === 1;
    }

    /** Record ONE mutation just performed (call only after the mutation actually ran). */
    public static function record(string $guardian, string $guardianClass): void
    {
        if (! self::enabled()) {
            return;
        }
        if (! self::available()) {
            Log::error('sentinel_breaker: mutation recorded БЕЗ предохранителя — библиотека недоступна (H4930).', [
                'guardian' => $guardian,
                'bin' => self::bin(),
            ]);

            return;
        }
        self::run($guardian, $guardianClass, ['record', $guardian]);
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

    /**
     * @param  list<string>  $args
     * @return int|null exit code; null when the process could not run
     */
    private static function run(string $guardian, string $guardianClass, array $args): ?int
    {
        $cmd = array_merge(
            [(string) config('services.sentinel_breaker.python', 'python3'), self::bin()],
            $args,
            ['--class', $guardianClass, '--state-dir', self::stateDir()],
        );
        $env = [
            'SENTINEL_BREAKER_ALARM_CMD' => (string) config(
                'services.sentinel_breaker.money_alarm_cmd',
                escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' guards:money-breaker-alarm',
            ),
        ];
        try {
            $process = new Process($cmd, base_path(), $env, null, 60);
            $process->run();

            return $process->getExitCode();
        } catch (Throwable $e) {
            Log::warning('sentinel_breaker CLI failed — fail-open (mutation proceeds).', [
                'guardian' => $guardian,
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return null;
        }
    }
}
