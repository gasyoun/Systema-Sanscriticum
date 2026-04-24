<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\UserSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Закрывает сессии, у которых давно не было heartbeat.
 *
 * Запускается по расписанию каждые 5 минут (см. app/Console/Kernel.php).
 *
 * Логика:
 * - Находим сессии с is_active=true и last_heartbeat_at старше N минут
 * - Для каждой: выставляем ended_at = last_heartbeat_at, пересчитываем duration_seconds
 * - Накапливаем total_time_spent в профиль юзера
 * - Пишем событие session_timeout
 *
 * Важно: ended_at = last_heartbeat_at (а не now()) — чтобы не засчитывать те 15 минут,
 * когда юзер уже не был на странице. Длительность будет максимально близкой к реальной.
 */
final class CloseStaleSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Через сколько минут простоя (без heartbeat) сессия считается зависшей.
     * 15 минут — стандартный таймаут для LMS.
     */
    private const STALE_AFTER_MINUTES = 15;

    /**
     * Сколько сессий обрабатываем за один запуск.
     * Ограничение — чтобы не грузить БД если вдруг скопилось 10000 сессий.
     */
    private const BATCH_SIZE = 500;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('tracking');
    }

    public function handle(): void
    {
        $threshold = now()->subMinutes(self::STALE_AFTER_MINUTES);
        
        // DEBUG START
    $found = UserSession::where('is_active', true)
        ->where('last_heartbeat_at', '<', $threshold)
        ->count();
    \Illuminate\Support\Facades\Log::info('CloseStaleSessionsJob DEBUG', [
        'threshold' => $threshold->toDateTimeString(),
        'found_count' => $found,
    ]);
    // DEBUG END

    $closedCount = 0;
        
        $closedCount = 0;
        $totalTimeAdded = 0;

        // Читаем батчами через chunk — не держим в памяти всё разом
        UserSession::query()
            ->where('is_active', true)
            ->where('last_heartbeat_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($sessions) use (&$closedCount, &$totalTimeAdded) {
                foreach ($sessions as $session) {
                    try {
                        DB::transaction(function () use ($session, &$totalTimeAdded) {
                            // Закрываем сессию с таймстемпом последнего heartbeat
                            $endedAt = $session->last_heartbeat_at ?? $session->started_at;
                            $duration = max(
                                0,
                                $endedAt->getTimestamp() - $session->started_at->getTimestamp()
                            );

                            DB::table('user_sessions')
                                ->where('id', $session->id)
                                ->update([
                                    'ended_at'         => $endedAt,
                                    'duration_seconds' => $duration,
                                    'is_active'        => false,
                                    'updated_at'       => now(),
                                ]);

                            // Накапливаем в профиль юзера
                            if ($duration > 0) {
                                DB::table('users')
                                    ->where('id', $session->user_id)
                                    ->increment('total_time_spent', $duration);

                                $totalTimeAdded += $duration;
                            }

                            // Пишем событие таймаута
                            DB::table('activity_events')->insert([
                                'user_id'    => $session->user_id,
                                'session_id' => $session->id,
                                'event_type' => ActivityEvent::TYPE_SESSION_TIMEOUT,
                                'event_data' => json_encode([
                                    'duration_seconds' => $duration,
                                    'reason'           => 'stale_heartbeat',
                                ], JSON_UNESCAPED_UNICODE),
                                'ip_address' => $session->ip_address,
                                'created_at' => now(),
                            ]);
                        });

                        $closedCount++;
                    } catch (\Throwable $e) {
                        // Одна упавшая сессия не должна ломать весь батч
                        Log::warning('CloseStaleSessionsJob: failed to close session', [
                            'session_id' => $session->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($closedCount > 0) {
            Log::info('CloseStaleSessionsJob: closed sessions', [
                'count'            => $closedCount,
                'total_time_added' => $totalTimeAdded,
            ]);
        }
    }
}