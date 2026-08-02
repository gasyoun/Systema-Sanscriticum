<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Safe-only soft auto-deploy remediation (H2148).
 *
 * Does:
 *  - discard tracked dirty paths that already match origin/<branch> (same rule as deploy.sh H2066)
 *  - optionally remove storage/auto_deploy.disabled when the fuse is soft-tagged AND no
 *    diverging dirty remains
 *
 * Never:
 *  - discard paths that diverge from origin
 *  - clear a hard (non soft-tagged) breaker
 *  - run deploy.sh / migrations / money paths
 */
final class SoftAutoDeployRemediator
{
    public const SOFT_BREAKER_TAGS = [
        '[rolled-back]',
        '[blocked-preflight]',
        '[blocked-dirty]',
        '[timeout-alive]',
    ];

    private const ALLOWED_DIRTY_RE = '#^public/docs/[^/]+\.pdf$#';

    public function __construct(
        private readonly string $appDir,
        private readonly string $branch = 'main',
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return array{
     *   status: 'ok'|'needs_human'|'error',
     *   exit_code: int,
     *   breaker_present: bool,
     *   breaker_soft: bool|null,
     *   breaker_last_line: string|null,
     *   dirty: list<string>,
     *   origin_equal: list<string>,
     *   diverging: list<string>,
     *   allowed_dirty: list<string>,
     *   actions: list<array{type: string, path?: string, applied: bool, detail: string}>,
     *   message: string
     * }
     */
    public function remediate(bool $dryRun = true, bool $applyBreakerClear = false): array
    {
        $appDir = rtrim($this->appDir, '/\\');
        $actions = [];

        if (! is_dir($appDir)) {
            return $this->errorResult('APP_DIR missing: '.$appDir);
        }

        $breakerPath = $appDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'auto_deploy.disabled';
        $breakerPresent = is_file($breakerPath);
        $breakerLast = null;
        $breakerSoft = null;
        if ($breakerPresent) {
            $raw = (string) @file_get_contents($breakerPath);
            $lines = preg_split('/\r\n|\n|\r/', trim($raw)) ?: [];
            $breakerLast = $lines === [] ? '' : (string) end($lines);
            $breakerSoft = $this->isSoftBreakerLine($breakerLast);
        }

        $dirty = $this->trackedDirtyPaths($appDir);
        if ($dirty === null) {
            return $this->errorResult('git status failed or unavailable in '.$appDir, [
                'breaker_present' => $breakerPresent,
                'breaker_soft' => $breakerSoft,
                'breaker_last_line' => $breakerLast,
            ]);
        }

        $allowed = [];
        $blocking = [];
        foreach ($dirty as $path) {
            if (preg_match(self::ALLOWED_DIRTY_RE, $path) === 1) {
                $allowed[] = $path;
            } else {
                $blocking[] = $path;
            }
        }

        if ($blocking !== [] && ! $this->gitFetchQuiet($appDir)) {
            // Still classify without fresh origin if possible; fail only if origin ref missing.
            if (! $this->originExists($appDir)) {
                return $this->errorResult('git fetch failed and origin/'.$this->branch.' missing', [
                    'breaker_present' => $breakerPresent,
                    'breaker_soft' => $breakerSoft,
                    'breaker_last_line' => $breakerLast,
                    'dirty' => $dirty,
                ]);
            }
        }

        $originEqual = [];
        $diverging = [];
        foreach ($blocking as $path) {
            if ($this->pathMatchesOrigin($appDir, $path)) {
                $originEqual[] = $path;
            } else {
                $diverging[] = $path;
            }
        }

        foreach ($originEqual as $path) {
            $applied = false;
            $detail = 'matches origin/'.$this->branch.' — safe discard';
            if (! $dryRun) {
                $ok = $this->checkoutPath($appDir, $path);
                $applied = $ok;
                $detail = $ok
                    ? 'git checkout HEAD -- '.$path
                    : 'FAILED git checkout HEAD -- '.$path;
                if (! $ok) {
                    $actions[] = ['type' => 'discard_origin_equal', 'path' => $path, 'applied' => false, 'detail' => $detail];

                    return $this->result(
                        status: 'error',
                        exitCode: 2,
                        message: 'checkout failed for '.$path,
                        breakerPresent: $breakerPresent,
                        breakerSoft: $breakerSoft,
                        breakerLast: $breakerLast,
                        dirty: $dirty,
                        originEqual: $originEqual,
                        diverging: $diverging,
                        allowed: $allowed,
                        actions: $actions,
                    );
                }
            }
            $actions[] = [
                'type' => 'discard_origin_equal',
                'path' => $path,
                'applied' => $applied,
                'detail' => $dryRun ? 'would: git checkout HEAD -- '.$path : $detail,
            ];
        }

        // After discards (or dry-run of them), remaining blocking dirty is diverging only.
        $remainingDiverging = $diverging;
        if (! $dryRun && $originEqual !== []) {
            $reDirty = $this->trackedDirtyPaths($appDir) ?? $dirty;
            $remainingDiverging = [];
            foreach ($reDirty as $path) {
                if (preg_match(self::ALLOWED_DIRTY_RE, $path) === 1) {
                    continue;
                }
                if (! $this->pathMatchesOrigin($appDir, $path)) {
                    $remainingDiverging[] = $path;
                }
            }
        }

        $canClearBreaker = $breakerPresent
            && $breakerSoft === true
            && $remainingDiverging === [];

        if ($breakerPresent && $breakerSoft === false) {
            $actions[] = [
                'type' => 'breaker_hard',
                'applied' => false,
                'detail' => 'hard fuse (no soft tag) — never auto-clear; human triage',
            ];

            return $this->result(
                status: 'needs_human',
                exitCode: 1,
                message: 'hard auto_deploy.disabled present — leave fuse until health/root-cause fixed',
                breakerPresent: true,
                breakerSoft: false,
                breakerLast: $breakerLast,
                dirty: $dirty,
                originEqual: $originEqual,
                diverging: $remainingDiverging,
                allowed: $allowed,
                actions: $actions,
            );
        }

        if ($remainingDiverging !== []) {
            $actions[] = [
                'type' => 'diverging_dirty',
                'applied' => false,
                'detail' => 'diverging: '.implode(', ', $remainingDiverging).' — PR or salvage; do not clear fuse',
            ];

            return $this->result(
                status: 'needs_human',
                exitCode: 1,
                message: 'diverging tracked dirty blocks safe remediation',
                breakerPresent: $breakerPresent,
                breakerSoft: $breakerSoft,
                breakerLast: $breakerLast,
                dirty: $dirty,
                originEqual: $originEqual,
                diverging: $remainingDiverging,
                allowed: $allowed,
                actions: $actions,
            );
        }

        if ($canClearBreaker && $applyBreakerClear) {
            $applied = false;
            if (! $dryRun) {
                $applied = @unlink($breakerPath);
                if (! $applied && is_file($breakerPath)) {
                    $actions[] = [
                        'type' => 'clear_soft_breaker',
                        'applied' => false,
                        'detail' => 'FAILED unlink '.$breakerPath,
                    ];

                    return $this->result(
                        status: 'error',
                        exitCode: 2,
                        message: 'could not remove soft breaker',
                        breakerPresent: true,
                        breakerSoft: true,
                        breakerLast: $breakerLast,
                        dirty: $dirty,
                        originEqual: $originEqual,
                        diverging: [],
                        allowed: $allowed,
                        actions: $actions,
                    );
                }
            }
            $actions[] = [
                'type' => 'clear_soft_breaker',
                'applied' => $applied,
                'detail' => $dryRun
                    ? 'would: rm storage/auto_deploy.disabled'
                    : 'removed storage/auto_deploy.disabled',
            ];
            $breakerPresent = $dryRun ? true : ! is_file($breakerPath);
        } elseif ($canClearBreaker && ! $applyBreakerClear) {
            $actions[] = [
                'type' => 'clear_soft_breaker',
                'applied' => false,
                'detail' => 'safe to clear soft fuse; pass --apply-breaker-clear to remove',
            ];
        } elseif (! $breakerPresent && $originEqual === [] && $remainingDiverging === []) {
            return $this->result(
                status: 'ok',
                exitCode: 0,
                message: 'nothing to remediate (no soft fuse, no blocking dirty)',
                breakerPresent: false,
                breakerSoft: null,
                breakerLast: null,
                dirty: $dirty,
                originEqual: [],
                diverging: [],
                allowed: $allowed,
                actions: $actions,
            );
        }

        $msg = $dryRun
            ? 'dry-run complete — review actions'
            : 'safe remediation applied';

        return $this->result(
            status: 'ok',
            exitCode: 0,
            message: $msg,
            breakerPresent: $breakerPresent,
            breakerSoft: $breakerSoft,
            breakerLast: $breakerLast,
            dirty: $dirty,
            originEqual: $originEqual,
            diverging: $remainingDiverging,
            allowed: $allowed,
            actions: $actions,
        );
    }

    public function isSoftBreakerLine(string $line): bool
    {
        foreach (self::SOFT_BREAKER_TAGS as $tag) {
            if (str_contains($line, $tag)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function trackedDirtyPaths(string $appDir): ?array
    {
        try {
            $result = Process::timeout($this->timeout)
                ->path($appDir)
                ->run(['git', 'status', '--porcelain', '--untracked-files=no']);
        } catch (Throwable) {
            return null;
        }
        if (! $result->successful()) {
            return null;
        }

        $paths = [];
        foreach (preg_split('/\r\n|\n|\r/', $result->output()) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(explode(' -> ', $path, 2)[1]);
            }
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function gitFetchQuiet(string $appDir): bool
    {
        try {
            $result = Process::timeout($this->timeout)
                ->path($appDir)
                ->run(['git', 'fetch', '-q', 'origin']);
        } catch (Throwable) {
            return false;
        }

        return $result->successful();
    }

    private function originExists(string $appDir): bool
    {
        try {
            $result = Process::timeout($this->timeout)
                ->path($appDir)
                ->run(['git', 'rev-parse', '--verify', '-q', 'origin/'.$this->branch]);
        } catch (Throwable) {
            return false;
        }

        return $result->successful();
    }

    private function pathMatchesOrigin(string $appDir, string $path): bool
    {
        try {
            $result = Process::timeout($this->timeout)
                ->path($appDir)
                ->run(['git', 'diff', '--quiet', 'origin/'.$this->branch, '--', $path]);
        } catch (Throwable) {
            return false;
        }

        // exit 0 = no diff; 1 = differs; other = error
        return $result->exitCode() === 0;
    }

    private function checkoutPath(string $appDir, string $path): bool
    {
        try {
            $result = Process::timeout($this->timeout)
                ->path($appDir)
                ->run(['git', 'checkout', 'HEAD', '--', $path]);
        } catch (Throwable) {
            return false;
        }

        return $result->successful();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function errorResult(string $message, array $extra = []): array
    {
        return array_merge([
            'status' => 'error',
            'exit_code' => 2,
            'breaker_present' => false,
            'breaker_soft' => null,
            'breaker_last_line' => null,
            'dirty' => [],
            'origin_equal' => [],
            'diverging' => [],
            'allowed_dirty' => [],
            'actions' => [],
            'message' => $message,
        ], $extra, ['status' => 'error', 'exit_code' => 2, 'message' => $message]);
    }

    /**
     * @param  list<string>  $dirty
     * @param  list<string>  $originEqual
     * @param  list<string>  $diverging
     * @param  list<string>  $allowed
     * @param  list<array{type: string, path?: string, applied: bool, detail: string}>  $actions
     * @return array<string, mixed>
     */
    private function result(
        string $status,
        int $exitCode,
        string $message,
        bool $breakerPresent,
        ?bool $breakerSoft,
        ?string $breakerLast,
        array $dirty,
        array $originEqual,
        array $diverging,
        array $allowed,
        array $actions,
    ): array {
        return [
            'status' => $status,
            'exit_code' => $exitCode,
            'breaker_present' => $breakerPresent,
            'breaker_soft' => $breakerSoft,
            'breaker_last_line' => $breakerLast,
            'dirty' => array_values($dirty),
            'origin_equal' => array_values($originEqual),
            'diverging' => array_values($diverging),
            'allowed_dirty' => array_values($allowed),
            'actions' => $actions,
            'message' => $message,
        ];
    }
}
