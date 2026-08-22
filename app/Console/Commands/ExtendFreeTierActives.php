<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LessonAccessGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * H3329 — free-tier actives extension (MG ruling 22-08-2026,
 * MONETIZATION_PLAN_2026H2 §4): at the expiry evaluation moment, grant
 * holders of the H2566 sleeping-payers cohort who actually USED the gift
 * (any lesson view inside the trailing activity window) get their grants
 * extended to the explicit --until date; everyone else lapses on schedule
 * with no offers, and the daemon cohort file is rewritten to the actives
 * so the next monthly delivery does not re-gift lapsed users.
 *
 * The rule is human-ruled; this command only applies it deterministically.
 * It is NOT cron-scheduled on purpose — a human/agent runs it near expiry
 * (recommended: 13–14 September 2026 for the 15-09 cohort), because the
 * verdict depends on post-grant usage that does not exist earlier.
 *
 * Dry-run by default; --apply performs the writes:
 *   1. lesson_access_grants.expires_at → --until for actives' live grants
 *      (reason-matched, currently expiring within the cohort window);
 *   2. cohort file (membership.free_tier.cohort_file) rewritten to the
 *      active user ids, www-data readable, dated header kept.
 */
final class ExtendFreeTierActives extends Command
{
    protected $signature = 'membership:extend-free-tier-actives
        {--until= : Extension date, YYYY-MM-DD (e.g. 2026-10-15). Required with --apply}
        {--window=21 : Activity window in days — any lesson view inside it counts as active}
        {--reason=free_tier_h2566 : Grant reason label identifying the cohort}
        {--apply : Perform the extension and rewrite the cohort file}';

    protected $description = 'H3329: продлить гранты бесплатного уровня активным (просмотр урока за окно), когорту файла — только активные';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $reason = (string) $this->option('reason');
        $windowDays = max(1, (int) $this->option('window'));
        $since = now()->subDays($windowDays)->startOfDay();

        $until = trim((string) $this->option('until'));
        if ($apply && $until === '') {
            $this->error('--apply требует --until=YYYY-MM-DD (куда продлеваем).');

            return self::FAILURE;
        }
        $untilDate = $until !== '' ? Carbon::parse($until)->endOfDay() : null;

        $holderIds = LessonAccessGrant::query()
            ->where('reason', $reason)
            ->distinct()
            ->pluck('user_id');

        $activeIds = $holderIds->filter(fn (int $uid) => DB::table('lesson_views')
            ->where('user_id', $uid)
            ->where(function ($q) use ($since) {
                $q->where('created_at', '>=', $since)
                    ->orWhere('last_opened_at', '>=', $since)
                    ->orWhere('last_heartbeat_at', '>=', $since);
            })->exists());

        $passiveCount = $holderIds->count() - $activeIds->count();

        $this->info(sprintf(
            'Когорта %s: %d держателей · активных (урок за %d дн., с %s): %d · отпадающих: %d',
            $reason, $holderIds->count(), $windowDays, $since->toDateString(), $activeIds->count(), $passiveCount,
        ));

        if ($activeIds->isEmpty()) {
            $this->line('Активных нет — продлевать некому; файл когорты не менялся.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->line('СУХОЙ ПРОГОН — записи: повторить с --apply --until=YYYY-MM-DD');
            $this->line('Активные id: '.$activeIds->implode(','));

            return self::SUCCESS;
        }

        $extended = LessonAccessGrant::query()
            ->where('reason', $reason)
            ->whereIn('user_id', $activeIds)
            ->where('expires_at', '>', now())
            ->update(['expires_at' => $untilDate]);

        $this->info("Продлено грантов: {$extended} (до {$untilDate->toDateString()})");

        $path = trim((string) config('membership.free_tier.cohort_file', ''));
        if ($path !== '') {
            $full = str_starts_with($path, '/')
                ? $path
                : storage_path('app/'.ltrim($path, '/'));
            $header = sprintf(
                "# H2566 cohort — actives only (rule MG 22-08-2026, H3329): lesson view >= %s; extended to %s\n",
                $since->toDateString(), $untilDate->toDateString(),
            );
            try {
                $dir = dirname($full);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                file_put_contents($full, $header.implode(PHP_EOL, $activeIds->values()->all()).PHP_EOL);
                $this->info("Файл когорты перезаписан активными: {$full}");
            } catch (Throwable $e) {
                $this->error("Не удалось записать файл когорты ({$full}): ".$e->getMessage());
                $this->warn('Гранты продлены, но демон в октябре снова подарит урок отпавшим — почини файл вручную!');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
