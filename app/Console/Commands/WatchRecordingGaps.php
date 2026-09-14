<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RecordingGapAlert;
use App\Services\Recordings\N8nZoomExecutionProbe;
use App\Services\Recordings\N8nZoomExecutionRetrier;
use App\Services\Recordings\RecordingGapFinder;
use App\Support\TelegramGroupLink;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H3209: lesson happened, recording not in cabinet/TG by next morning.
 * Scheduled 08:00 Europe/Moscow. The scheduled run never touches n8n;
 * --retry-failed is an explicit opt-in lane for early-failed executions
 * (died pre-upload), see N8nZoomExecutionRetrier.
 *
 * H3557: дедуп переехал из Redis-кэша в таблицу recording_gap_alerts —
 * автодеплой сбрасывает кэш (~20 деплоев за 25-08-2026), и hourly --stale
 * проходы отправляли один и тот же алерт заново. Ключ — sha256 отпечатка
 * набора пробелов (schedule_id+дата), одинаковый для утреннего и дневного
 * окна одного инцидента. Успешная отправка = exit 0; FAILURE остался для
 * --dry и для случая «пробелы есть, но в TG уйти не удалось».
 */
class WatchRecordingGaps extends Command
{
    protected $signature = 'recordings:gap-watch
        {--dry : Table only; do not send Telegram}
        {--all : Include groups without telegram_chat_id}
        {--force : Send even if this gap set was already alerted (recording_gap_alerts)}
        {--date= : Single day YYYY-MM-DD (default: yesterday, app tz)}
        {--from= : Inclusive start YYYY-MM-DD (overrides --date)}
        {--until= : Inclusive end YYYY-MM-DD}
        {--retry-failed : Retry n8n executions that died before any upload (needs RECORDING_GAP_RETRY_FAILED_ENABLED)}
        {--stale : Today-only pass: flag slots started >= stale_hours ago with no recording}';

    protected $description = 'Alert when yesterday had a schedule but no matching published recording (H3209).';

    public function handle(RecordingGapFinder $finder, N8nZoomExecutionProbe $n8n, N8nZoomExecutionRetrier $retrier): int
    {
        [$from, $until] = $this->window();
        $includeWithoutChat = (bool) $this->option('all');

        $gaps = $finder->gaps($from, $until, $includeWithoutChat);
        if ((bool) $this->option('stale')) {
            $gaps = $this->filterStale($gaps);
            if ($gaps === []) {
                $this->info('Сегодняшних слотов старше '.(int) config('recording_gap.stale_hours', 4).' ч без записи нет.');

                return self::SUCCESS;
            }
        }
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

        $fingerprint = $this->fingerprint($gaps);
        if (! $this->option('force')) {
            $recent = RecordingGapAlert::query()
                ->where('fingerprint', $fingerprint)
                ->where('last_sent_at', '>=', now()->subHours(36))
                ->first();
            if ($recent !== null) {
                $this->comment(sprintf(
                    'Дедуп recording_gap_alerts #%d (%s) — тот же набор пробелов уже уехал, повторный алерт не шлём.',
                    $recent->id,
                    $recent->last_sent_at->timezone((string) config('app.timezone', 'Europe/Moscow'))->format('d-m-Y H:i'),
                ));

                return self::SUCCESS;
            }
        }

        $sent = $this->sendTelegram($payload);
        if ($sent) {
            $alert = RecordingGapAlert::query()->updateOrCreate(
                ['fingerprint' => $fingerprint],
                [
                    'window_label' => $from->toDateString().'…'.$until->toDateString(),
                    'first_sent_at' => now(),
                    'last_sent_at' => now(),
                ],
            );
            if ($alert->wasRecentlyCreated === false) {
                $alert->fill([
                    'send_count' => $alert->send_count + 1,
                    'last_sent_at' => now(),
                ])->save();
            }

            Log::info('recordings:gap-watch alert sent', [
                'fingerprint' => $fingerprint,
                'gaps' => count($gaps),
                'window' => $from->toDateString().'…'.$until->toDateString(),
                'alert_id' => $alert->id,
                'send_count' => $alert->send_count,
            ]);

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Отпечаток набора пробелов: не зависит от окна (утреннее «вчера» против
     * дневного --stale «сегодня» дают один ключ на один инцидент) и меняется,
     * когда к инциденту добавляется новый слот.
     *
     * @param  list<array{schedule_id: int, lesson_date: string, start: string, course_id: int, course: string, group_id: ?int, group: string, chat_id: string, reason: string}>  $gaps
     */
    private function fingerprint(array $gaps): string
    {
        $parts = array_map(
            static fn (array $gap): string => $gap['schedule_id'].':'.$gap['lesson_date'],
            $gaps,
        );
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * --stale pass keeps only today's slots whose start is at least
     * stale_hours in the past (Zoom+pipeline SLA) and still has no recording.
     *
     * @param  list<array{schedule_id: int, lesson_date: string, start: string, course_id: int, course: string, group_id: ?int, group: string, chat_id: string, reason: string}>  $gaps
     * @return list<array{schedule_id: int, lesson_date: string, start: string, course_id: int, course: string, group_id: ?int, group: string, chat_id: string, reason: string}>
     */
    private function filterStale(array $gaps): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $threshold = CarbonImmutable::now($tz)->subHours(max(1, (int) config('recording_gap.stale_hours', 4)));

        return array_values(array_filter(
            $gaps,
            static fn (array $gap): bool => CarbonImmutable::parse($gap['start'], $tz)->lte($threshold),
        ));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(): array
    {
        if ((bool) $this->option('stale')) {
            $today = CarbonImmutable::now((string) config('app.timezone', 'Europe/Moscow'));

            return [$today->startOfDay(), $today];
        }

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
     * @param  array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string, webhook_token:?string}  $n8n
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
            $group = TelegramGroupLink::anchor($gap['chat_id'], $gap['group'] !== '' ? $gap['group'] : 'группа');
            $course = e($gap['course']);
            $gid = $gap['group_id'] !== null ? (string) $gap['group_id'] : '—';
            // H3557: чем старше занятие, тем ближе смерть download_token вебхука
            // (эмпирика ~24ч). Громкий маркер заставляет дёргать трубу ДО смерти токена.
            $aging = '';
            $ageHours = CarbonImmutable::parse($gap['start'], (string) config('app.timezone', 'Europe/Moscow'))->diffInHours(now((string) config('app.timezone', 'Europe/Moscow')));
            if ($ageHours >= 20) {
                $aging = ' ⚠️ <b>токен записи истекает — срочно resume, иначе запись вернётся только вручную</b>';
            }
            $lines[] = '• '.$gap['start']
                .' · course '.$gap['course_id'].' '.$course
                .' · group '.$gid.' '.$group
                .' · '.$gap['reason'].$aging;
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
     * @param  array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string, webhook_token:?string}  $n8n
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

        $line = implode(' · ', $parts);

        // H3952: a fresh-link failure used to be indistinguishable from «вебхук не пришёл»
        // — the run exited green and every diagnostic pointed at a missing webhook. The
        // workflow now stamps its verdict into the thrown error and this line names it,
        // with the webhook-token HEAD as the corroborating evidence.
        $verdict = N8nZoomExecutionProbe::verdictFor($n8n['error_class'] ?? null);
        if ($verdict !== null) {
            $line .= "\n".'↳ вердикт: '.$verdict;
        }

        $token = $n8n['webhook_token'] ?? null;
        if ($token !== null) {
            $line .= "\n".'↳ вебхук-токен: '.match ($token) {
                'alive' => 'ЖИВ (HEAD 2xx/3xx) — запись есть в облаке, значит это сбой credential/fetch, а не пропавший вебхук',
                'dead' => 'мёртв — за пределами ~24 ч окна, свежую ссылку брать через per-account fresh-link',
                'absent' => 'в прогоне нет подписанной ссылки',
                default => $token,
            };
        }

        return $line;
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
            // H3557: копия в отдел заботы помечена заголовком, чтобы два чата
            // читались как адресаты, а не как дубль одного сообщения.
            $text = $chatId === $careId && $careId !== ''
                ? "<b>[Отдел заботы]</b>\n".$text
                : $text;
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
