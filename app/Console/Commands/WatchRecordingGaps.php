<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Recordings\N8nZoomExecutionProbe;
use App\Services\Recordings\N8nZoomExecutionRetrier;
use App\Services\Recordings\RecordingGapFinder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H3209: lesson happened, recording not in cabinet/TG by next morning.
 * Scheduled 08:00 Europe/Moscow. The scheduled run never touches n8n;
 * --retry-failed is an explicit opt-in lane for early-failed executions
 * (died pre-upload), see N8nZoomExecutionRetrier.
 */
class WatchRecordingGaps extends Command
{
    protected $signature = 'recordings:gap-watch
        {--dry : Table only; do not send Telegram}
        {--all : Include groups without telegram_chat_id}
        {--force : Send even if recording_gap:DATE already alerted}
        {--date= : Single day YYYY-MM-DD (default: yesterday, app tz)}
        {--from= : Inclusive start YYYY-MM-DD (overrides --date)}
        {--until= : Inclusive end YYYY-MM-DD}
        {--retry-failed : Retry n8n executions that died before any upload (needs RECORDING_GAP_RETRY_FAILED_ENABLED)}';

    protected $description = 'Alert when yesterday had a schedule but no matching published recording (H3209).';

    public function handle(RecordingGapFinder $finder, N8nZoomExecutionProbe $n8n, N8nZoomExecutionRetrier $retrier): int
    {
        [$from, $until] = $this->window();
        $includeWithoutChat = (bool) $this->option('all');

        $gaps = $finder->gaps($from, $until, $includeWithoutChat);
        $n8nExec = $n8n->lastLiveZoomExecution();

        if ($gaps === []) {
            $this->info('Пробелов записей нет за '.$from->toDateString().'…'.$until->toDateString().'.');
            $this->line($this->n8nLine($n8nExec));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($gaps as $gap) {
            $rows[] = [
                $gap['lesson_date'],
                $gap['start'],
                (string) $gap['course_id'],
                $gap['course'],
                $gap['group_id'] !== null ? (string) $gap['group_id'] : '',
                $gap['group'],
                $gap['reason'],
            ];
        }
        $this->table(['date', 'start', 'course_id', 'course', 'group_id', 'group', 'reason'], $rows);
        $this->line($this->n8nLine($n8nExec));

        $retryReport = ['items' => [], 'posted' => 0];
        if ((bool) $this->option('retry-failed')) {
            if (! (bool) config('recording_gap.retry_enabled')) {
                $this->warn('Ретрай выключен: RECORDING_GAP_RETRY_FAILED_ENABLED=false — только алерт.');
            } else {
                $retryReport = $this->runRetryLane(
                    $retrier,
                    $from,
                    $until,
                    execute: ! $this->option('dry'),
                );
                $this->renderRetryTable($retryReport['items']);
            }
        }

        $payload = $this->buildTelegram($gaps, $n8nExec, $from, $until, $retryReport['items']);
        $this->line('--- TG payload ---');
        $this->line($payload);

        if ($this->option('dry')) {
            $this->comment('--dry: Telegram не отправлен.');
            if ((bool) $this->option('retry-failed') && (bool) config('recording_gap.retry_enabled')) {
                $this->comment('--dry: ретраи не отправлены.');
            }

            return self::FAILURE;
        }

        $dedupeKey = 'recording_gap:'.$from->toDateString();
        if ($from->toDateString() !== $until->toDateString()) {
            $dedupeKey .= ':'.$until->toDateString();
        }

        if (! $this->option('force') && Cache::get($dedupeKey)) {
            $this->comment('Дедуп '.$dedupeKey.' — повторный алерт не шлём.');

            return self::FAILURE;
        }

        $sent = $this->sendTelegram($payload);
        if ($sent) {
            Cache::put($dedupeKey, true, now()->addHours(36));
        }

        return self::FAILURE;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $fromOpt = $this->option('from');
        $untilOpt = $this->option('until');
        $dateOpt = $this->option('date');

        if (is_string($fromOpt) && $fromOpt !== '') {
            $from = CarbonImmutable::parse($fromOpt, $tz)->startOfDay();
            $until = is_string($untilOpt) && $untilOpt !== ''
                ? CarbonImmutable::parse($untilOpt, $tz)->endOfDay()
                : $from->endOfDay();

            return [$from, $until];
        }

        $day = is_string($dateOpt) && $dateOpt !== ''
            ? CarbonImmutable::parse($dateOpt, $tz)
            : CarbonImmutable::now($tz)->subDay();

        return [$day->startOfDay(), $day->endOfDay()];
    }

    /**
     * @param  list<array{schedule_id: int, lesson_date: string, start: string, course_id: int, course: string, group_id: ?int, group: string, chat_id: string, reason: string}>  $gaps
     * @param  array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string}  $n8n
     * @param  list<array{id: string, started_at: ?string, last_node: ?string, safe: bool, superseded: bool, retried_before: bool, error_class: ?string, action: string}>  $retryItems
     */
    private function buildTelegram(array $gaps, array $n8n, CarbonImmutable $from, CarbonImmutable $until, array $retryItems = []): string
    {
        $range = $from->toDateString() === $until->toDateString()
            ? $from->format('d-m-Y')
            : $from->format('d-m-Y').'…'.$until->format('d-m-Y');

        $lines = [
            '<b>Записи не в кабинете / ТГ</b> за '.$range,
            '',
        ];
        foreach ($gaps as $gap) {
            $group = $gap['group'] !== '' ? e($gap['group']) : '—';
            $course = e($gap['course']);
            $gid = $gap['group_id'] !== null ? (string) $gap['group_id'] : '—';
            $lines[] = '• '.$gap['start']
                .' · course '.$gap['course_id'].' '.$course
                .' · group '.$gid.' '.$group
                .' · '.$gap['reason'];
        }
        if ($retryItems !== []) {
            $lines[] = '';
            $lines[] = '<b>Ретрай упавших запусков (--retry-failed)</b>';
            foreach ($retryItems as $item) {
                $node = $item['last_node'] !== null ? ' на «'.$item['last_node'].'»' : '';
                $lines[] = '• exec '.$item['id'].$node.' — '.e($item['action']);
            }
        }
        $lines[] = '';
        $lines[] = $this->n8nLine($n8n);
        $lines[] = '';
        $lines[] = 'Не перезапускать ZOOM 1.4 с вебхука (повторный YouTube/Rutube). Resume с AI Agent1.';
        $lines[] = '<code>php artisan recordings:gap-watch --dry</code>';

        return implode("\n", $lines);
    }

    /**
     * @param  array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string}  $n8n
     */
    private function n8nLine(array $n8n): string
    {
        $wf = (string) config('recording_gap.n8n_workflow_id', '1EIqqNzMl5NNIxST');
        if ($n8n['note'] && ! $n8n['id']) {
            return 'n8n '.$wf.': '.$n8n['note'];
        }
        $parts = ['n8n ZOOM 1.4 TEST '.$wf];
        if ($n8n['id']) {
            $parts[] = 'exec '.$n8n['id'];
        }
        if ($n8n['status']) {
            $parts[] = $n8n['status'];
        }
        if ($n8n['error_class']) {
            $parts[] = $n8n['error_class'];
        }
        if ($n8n['started_at']) {
            $parts[] = 'started '.$n8n['started_at'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Opt-in lane: classify failed executions in the widened window and
     * (when $execute) POST n8n retries for the safe, unclaimed ones.
     *
     * @param  bool  $execute  false in --dry: verdicts only, nothing posted
     * @return array{items: list<array{id: string, started_at: ?string, last_node: ?string, safe: bool, superseded: bool, retried_before: bool, error_class: ?string, action: string}>, posted: int}
     */
    private function runRetryLane(N8nZoomExecutionRetrier $retrier, CarbonImmutable $from, CarbonImmutable $until, bool $execute): array
    {
        $slack = max(0, (int) config('recording_gap.retry_window_slack_days', 1));
        $max = max(1, (int) config('recording_gap.retry_max_per_run', 5));

        $failed = $retrier->failedInWindow(
            $from->utc()->startOfDay(),
            $until->utc()->endOfDay()->addDays($slack),
        );

        usort($failed, fn (array $a, array $b): int => strcmp((string) $a['started_at'], (string) $b['started_at']));

        $posted = 0;
        foreach ($failed as &$item) {
            if ($item['retried_before']) {
                $item['action'] = 'skip: уже ретраили ранее';
            } elseif ($item['superseded']) {
                $item['action'] = 'skip: уже есть успешный ретрай';
            } elseif (! $item['safe']) {
                $item['action'] = 'skip: не безопасен — вручную, resume с AI Agent1';
            } elseif ($execute && $posted < $max) {
                $result = $retrier->retry($item['id']);
                if ($result['ok']) {
                    $posted++;
                    $item['action'] = 'ретрай отправлен (HTTP '.$result['http'].')';
                } else {
                    $item['action'] = 'ошибка ретрая (HTTP '.$result['http'].')'.($result['note'] !== null ? ': '.$result['note'] : '');
                }
            } elseif ($execute) {
                $item['action'] = 'skip: лимит ретраев на прогон ('.$max.')';
            } else {
                $item['action'] = 'был бы отправлен (dry)';
            }
        }
        unset($item);

        return ['items' => $failed, 'posted' => $posted];
    }

    /**
     * @param  list<array{id: string, started_at: ?string, last_node: ?string, safe: bool, superseded: bool, retried_before: bool, error_class: ?string, action: string}>  $items
     */
    private function renderRetryTable(array $items): void
    {
        if ($items === []) {
            $this->line('Ретрай: упавших запусков в окне нет.');

            return;
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                $item['id'],
                (string) $item['started_at'],
                (string) ($item['error_class'] ?? '—'),
                (string) ($item['last_node'] ?? '—'),
                $item['action'],
            ];
        }
        $this->table(['exec', 'started', 'error', 'last node', 'action'], $rows);
    }

    private function sendTelegram(string $text): bool
    {
        $token = (string) config('services.telegram.bot_token', '');
        $chatIds = $this->parseChatIds(config('recording_gap.telegram_chat_id'));
        $careId = trim((string) config('recording_gap.care_telegram_chat_id', ''));
        if ($careId !== '' && ! in_array($careId, $chatIds, true)) {
            $chatIds[] = $careId;
        }
        if ($token === '' || $chatIds === []) {
            $this->warn('TELEGRAM_BOT_TOKEN или RECORDING_GAP_TELEGRAM_CHAT_ID пусты — алерт не ушёл.');

            return false;
        }

        $any = false;
        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout(15)
                    ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if ($response->successful() && ($response->json('ok') ?? false)) {
                    $any = true;
                    $this->info('TG → '.$chatId);
                } else {
                    Log::warning('recordings:gap-watch tg fail', ['chat_id' => $chatId, 'body' => $response->body()]);
                    if ($chatId === $careId && $careId !== '') {
                        $this->warn('Отдел заботы ('.$careId.') не получил алерт: '.mb_substr($response->body(), 0, 120));
                    }
                }
            } catch (Throwable $e) {
                Log::warning('recordings:gap-watch tg error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }

        return $any;
    }

    /**
     * @return list<string>
     */
    private function parseChatIds(mixed $raw): array
    {
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim((string) $raw)) ?: [];

        return array_values(array_filter(array_map('strval', $parts), static fn (string $id): bool => $id !== ''));
    }
}
