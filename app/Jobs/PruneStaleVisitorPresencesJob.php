<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupportVisitorPresence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Выметает устаревшие строки присутствия (H1197, Jivo-паритет Pillar 2) — те,
 * чей последний beacon старше config('support_presence.prune_after_minutes').
 * Запускается по расписанию каждые 5 минут (app/Console/Kernel.php), по образцу
 * {@see CloseStaleSessionsJob}. Таблица эфемерная: держим ровно столько, сколько
 * нужно куратору, персональные данные анонимного посетителя не залёживаются.
 */
final class PruneStaleVisitorPresencesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $minutes = (int) config('support_presence.prune_after_minutes', 15);
        $threshold = now()->subMinutes($minutes);

        $deleted = SupportVisitorPresence::query()
            ->where('last_seen_at', '<', $threshold)
            ->orWhereNull('last_seen_at')
            ->delete();

        if ($deleted > 0) {
            Log::info('PruneStaleVisitorPresencesJob: removed stale presences', [
                'count' => $deleted,
                'older_than_minutes' => $minutes,
            ]);
        }
    }
}
