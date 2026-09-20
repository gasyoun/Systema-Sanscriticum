<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LessonView;
use App\Models\SrsReviewLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * H5184 N07 (Uprava vote) — churn-сигнал «stalled_7d»: у студента была
 * активность в последние 30 дней, но в последние 7 — ни одной.
 *
 * АКТИВНОСТЬ = две таблицы (обе реально пишутся на проде):
 *   - srs_review_logs.reviewed_at — журнал повторений ({@see SrsReviewLog});
 *   - lesson_views.last_opened_at — открытия уроков ({@see LessonView}).
 * users.last_activity_at (TrackUserActivity) намеренно НЕ используем: это
 * любой pageview, включая маркетинговые страницы — сигнал был бы шумнее.
 *
 * Раскол A/B детерминированный по хешу user_id (50/50): crc32(id) % 2 →
 * intervention | control. Дедуп: юзер не сигналим чаще раза в 14 дней.
 * Состояние — storage/app/churn-signals-state.json (без новых миграций).
 *
 * Вызов:
 *   edtech:churn-signals            собрать сигналы, напечатать JSON, POST в n8n
 *   edtech:churn-signals --dry-run  только печать payload, без POST и записи
 *   edtech:churn-signals --d7       отчёт: была ли активность в 7 дней после
 *                                   сигнала, split intervention/control
 *
 * Вебхук — тот же n8n-паттерн, что schedule→sheet (X-Webhook-Secret, H1960);
 * пустой N8N_CHURN_WEBHOOK_URL — no-op с warning.
 */
class ChurnSignalsCommand extends Command
{
    protected $signature = 'edtech:churn-signals {--dry-run} {--d7}';

    protected $description = 'Churn-сигнал «активность 30д есть, 7д нет» → JSON в n8n-вебхук (H5184 N07)';

    private const STATE_FILE = 'churn-signals-state.json';

    /** Повторный сигнал тому же юзеру не раньше, чем через N дней. */
    private const RESIGNAL_AFTER_DAYS = 14;

    public function handle(): int
    {
        return $this->option('d7') ? $this->d7Report() : $this->collectSignals();
    }

    private function collectSignals(): int
    {
        $now = Carbon::now();
        $cutoff30 = $now->copy()->subDays(30);
        $cutoff7 = $now->copy()->subDays(7);

        $lastActivity = $this->lastActivityByUser($cutoff30);

        // Дедуп: кто уже был сигнален за последние 14 дней.
        $state = $this->readState();
        $recentlySignaled = [];
        $resignalFloor = $now->copy()->subDays(self::RESIGNAL_AFTER_DAYS);
        foreach ($state as $entry) {
            if (isset($entry['user_id'], $entry['signaled_at'])
                && Carbon::parse($entry['signaled_at'])->gt($resignalFloor)) {
                $recentlySignaled[(int) $entry['user_id']] = true;
            }
        }

        $users = User::query()
            ->whereIn('id', array_keys($lastActivity))
            ->get(['id', 'name', 'email', 'telegram_id'])
            ->keyBy('id');

        $signals = [];
        foreach ($lastActivity as $userId => $lastAt) {
            if ($lastAt->gte($cutoff7)) {
                continue; // активен в последние 7 дней — не застыл
            }
            if (isset($recentlySignaled[$userId])) {
                continue; // сигналили меньше 14 дней назад
            }
            $user = $users->get($userId);
            if ($user === null) {
                continue;
            }
            $signals[] = [
                'user_id' => (int) $userId,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'tg_id' => $user->telegram_id !== null ? (int) $user->telegram_id : null,
                'signal' => 'stalled_7d',
                'days_stuck' => (int) $lastAt->diffInDays($now),
                'bucket' => $this->bucket((int) $userId),
            ];
        }

        usort($signals, fn (array $a, array $b) => $a['user_id'] <=> $b['user_id']);

        if ($this->option('dry-run')) {
            $this->printJson($signals);
            $this->warn('DRY-RUN: сигналов '.count($signals).', POST и запись состояния пропущены.');

            return self::SUCCESS;
        }

        foreach ($signals as $s) {
            $state[] = [
                'user_id' => $s['user_id'],
                'bucket' => $s['bucket'],
                'signaled_at' => $now->toIso8601String(),
                'days_stuck' => $s['days_stuck'],
            ];
        }
        $this->writeState($state);

        $this->printJson($signals);
        $this->postToWebhook($signals);
        $this->info('Сигналов: '.count($signals).'.');

        return self::SUCCESS;
    }

    /**
     * d7-отчёт: для каждой записи состояния — была ли у юзера активность
     * (SRS или открытие урока) в 7 дней после signaled_at.
     */
    private function d7Report(): int
    {
        $state = $this->readState();
        if ($state === []) {
            $this->warn('Состояние пусто ('.Storage::disk('local')->path(self::STATE_FILE).') — отчёта нет.');

            return self::SUCCESS;
        }

        $summary = [
            'type' => 'd7_report',
            'intervention' => ['n' => 0, 'reengaged' => 0],
            'control' => ['n' => 0, 'reengaged' => 0],
        ];

        foreach ($state as $entry) {
            $bucketKey = $entry['bucket'] ?? null;
            if (! isset($summary[$bucketKey], $entry['user_id'], $entry['signaled_at'])) {
                continue;
            }
            $summary[$bucketKey]['n']++;

            $signaledAt = Carbon::parse($entry['signaled_at']);
            if ($this->hadActivityBetween((int) $entry['user_id'], $signaledAt, $signaledAt->copy()->addDays(7))) {
                $summary[$bucketKey]['reengaged']++;
            }
        }

        $this->printJson($summary);
        $this->postToWebhook($summary);

        return self::SUCCESS;
    }

    /**
     * Последняя активность (SRS-повторение или открытие урока) по каждому
     * юзеру в пределах окна 30 дней. Два groupBy-запроса, слияние в PHP.
     *
     * @return array<int, Carbon> user_id => последняя активность
     */
    private function lastActivityByUser(Carbon $cutoff30): array
    {
        $last = [];

        SrsReviewLog::query()
            ->where('reviewed_at', '>=', $cutoff30)
            ->selectRaw('user_id, MAX(reviewed_at) as last_at')
            ->groupBy('user_id')
            ->each(function ($row) use (&$last) {
                $last[(int) $row->user_id] = Carbon::parse($row->last_at);
            });

        LessonView::query()
            ->where('last_opened_at', '>=', $cutoff30)
            ->selectRaw('user_id, MAX(last_opened_at) as last_at')
            ->groupBy('user_id')
            ->each(function ($row) use (&$last) {
                $at = Carbon::parse($row->last_at);
                $userId = (int) $row->user_id;
                if (! isset($last[$userId]) || $last[$userId]->lt($at)) {
                    $last[$userId] = $at;
                }
            });

        return $last;
    }

    private function hadActivityBetween(int $userId, Carbon $from, Carbon $to): bool
    {
        $reviewed = SrsReviewLog::query()
            ->where('user_id', $userId)
            ->whereBetween('reviewed_at', [$from, $to])
            ->exists();
        if ($reviewed) {
            return true;
        }

        return LessonView::query()
            ->where('user_id', $userId)
            ->whereBetween('last_opened_at', [$from, $to])
            ->exists();
    }

    /** Детерминированный 50/50-раскол по хешу user_id. */
    private function bucket(int $userId): string
    {
        return crc32((string) $userId) % 2 === 0 ? 'intervention' : 'control';
    }

    /** @return array<int, array<string, mixed>> */
    private function readState(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::STATE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) $disk->get(self::STATE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, array<string, mixed>> $state */
    private function writeState(array $state): void
    {
        Storage::disk('local')->put(
            self::STATE_FILE,
            json_encode(array_values($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    private function printJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Тот же n8n-паттерн, что у schedule→sheet (X-Webhook-Secret, H1960):
     * секрет уходит заголовком; пустой URL — no-op с warning.
     */
    private function postToWebhook(array $payload): void
    {
        $url = (string) config('services.n8n.churn_webhook');
        if ($url === '') {
            Log::warning('ChurnSignals: N8N_CHURN_WEBHOOK_URL не задан — пропуск.');
            $this->warn('Вебхук n8n не настроен (N8N_CHURN_WEBHOOK_URL) — отправка пропущена.');

            return;
        }

        $headers = [];
        $secret = (string) config('services.n8n.churn_webhook_secret');
        if ($secret !== '') {
            $headers['X-Webhook-Secret'] = $secret;
        }

        try {
            $response = Http::withHeaders($headers)->timeout(15)->post($url, $payload);
            if (! $response->successful()) {
                Log::error('ChurnSignals: n8n вернул '.$response->status(), ['body' => $response->body()]);
                $this->error('n8n вернул статус '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::error('ChurnSignals: сбой отправки в n8n: '.$e->getMessage());
            $this->error('Сбой отправки: '.$e->getMessage());
        }
    }
}
