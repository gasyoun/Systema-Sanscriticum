<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Services\Support\SupportDmAutoReply;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * H3765 A1: перепись задержки приёма входящих поддержки — ЧИТАЮЩАЯ, ничего
 * не меняет и никуда не шлёт.
 *
 * Зачем. Автоответчик пропускает как «протухшее» всё, что старше
 * services.telegram_support.auto_reply_max_age_hours, и таких пропусков за
 * две недели набралось ~1070. Вопрос, ради которого написана команда: это
 * ЖИВЫЕ вопросы студентов, до которых конвейер не успел (тогда виноват такт
 * синка, и лечить надо его), или это дозабор истории диалогов, который тащит
 * сообщения годичной давности (тогда пропуск — правильное поведение, и
 * поднимать потолок бессмысленно).
 *
 * Различить их можно ровно одним измерением, и команда его делает:
 *  - ЛАГ ПРИЁМА (sent_at → created_at) — сколько сообщение пролежало у
 *    Telegram до того, как попало к нам. Большой лаг = дозабор истории.
 *  - ЛАГ ОБРАБОТКИ (created_at сообщения → created_at события) — сколько мы
 *    сами тянули после приёма. Большой лаг = проблема такта/очереди.
 * Первое — не наша вина, второе — наша.
 *
 * Считаем в PHP, а не в SQL: TIMESTAMPDIFF есть в MySQL и нет в SQLite, на
 * котором идут тесты, а объём окна (тысячи строк) в память укладывается.
 */
class SupportIngestLatencyReport extends Command
{
    protected $signature = 'support:ingest-latency-report
        {--days=30 : Окно переписи в днях}
        {--write= : Записать отчёт в файл (по умолчанию docs/RESULTS_SUPPORT_INGEST_LATENCY_<дата>.md)}
        {--no-write : Только показать в консоли}';

    protected $description = 'H3765 A1: перепись лага приёма и лага обработки входящих Telegram-поддержки; называет причину stale-пропусков. Только чтение.';

    /** Лаг приёма ниже этого — сообщение считается живым, а не дозабором истории. */
    private const LIVE_INGEST_LAG_MINUTES = 60;

    /** Медиана лага ОБРАБОТКИ выше этого — конвейер действительно не успевает. */
    private const CADENCE_BUG_PROCESSING_LAG_MINUTES = 60;

    /** Доля stale-пропусков старше недели, выше которой причина — дозабор истории. */
    private const BACKFILL_DOMINANT_SHARE = 0.5;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $accounts = TelegramSupportAccount::query()->pluck('name', 'id')->all();

        $incoming = TelegramSupportMessage::query()
            ->where('direction', 'incoming')
            ->where('created_at', '>=', $since)
            ->whereNotNull('sent_at')
            ->get(['id', 'telegram_support_account_id', 'telegram_chat_id', 'telegram_message_id', 'sent_at', 'created_at']);

        $events = SupportAiReplyEvent::query()
            ->whereIn('event_type', [SupportDmAutoReply::EVENT_STALE_SKIP, SupportDmAutoReply::EVENT_HINTED, SupportDmAutoReply::EVENT_SENT])
            ->where('created_at', '>=', $since)
            ->get(['telegram_support_message_id', 'event_type', 'created_at']);

        $messageById = $incoming->keyBy('id');

        $lines = [];
        $lines[] = '# RESULTS — перепись лага приёма Telegram-поддержки (H3765 A1)';
        $lines[] = '';
        $lines[] = '_Created: '.now()->format('d-m-Y').' · Last updated: '.now()->format('d-m-Y').'_';
        $lines[] = '';
        $lines[] = sprintf(
            'Окно: **%d дн.** (с %s по %s). Строк входящих с известным `sent_at`: **%d**.',
            $days,
            $since->format('d-m-Y'),
            now()->format('d-m-Y'),
            $incoming->count(),
        );
        $lines[] = '';
        $lines[] = 'Читающая перепись, порождена `php artisan support:ingest-latency-report`. Ничего не меняет.';
        $lines[] = '';

        $lines = array_merge($lines, $this->dailySection($incoming));
        $lines = array_merge($lines, $this->accountSection($incoming, $accounts));
        $lines = array_merge($lines, $this->staleBandSection($events, $messageById));

        $processing = $this->processingLagMinutes($events, $messageById);
        $lines = array_merge($lines, $this->processingSection($processing));
        $lines = array_merge($lines, $this->verdictSection($events, $messageById, $processing, $incoming));

        $lines[] = '';
        $lines[] = '_Dr. Mārcis Gasūns_';
        $lines[] = '';

        $report = implode("\n", $lines);
        $this->line($report);

        if ($this->option('no-write')) {
            return self::SUCCESS;
        }

        $path = (string) ($this->option('write')
            ?: base_path('docs/RESULTS_SUPPORT_INGEST_LATENCY_'.now()->format('d-m-Y').'.md'));

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $report);
        $this->info("Отчёт записан: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, TelegramSupportMessage>  $incoming
     * @return list<string>
     */
    private function dailySection($incoming): array
    {
        $byDay = [];
        foreach ($incoming as $message) {
            $day = $message->created_at?->format('Y-m-d') ?? '—';
            $lag = $this->minutesBetween($message->sent_at, $message->created_at);
            $byDay[$day] ??= ['live' => 0, 'backfill' => 0, 'lags' => [], 'oldest' => null];
            $byDay[$day][$lag < self::LIVE_INGEST_LAG_MINUTES ? 'live' : 'backfill']++;
            $byDay[$day]['lags'][] = $lag;
            $oldest = $byDay[$day]['oldest'];
            if ($oldest === null || ($message->sent_at !== null && $message->sent_at->lt($oldest))) {
                $byDay[$day]['oldest'] = $message->sent_at;
            }
        }
        krsort($byDay);

        $lines = ['## 1. По дням приёма: живое против дозабора истории', ''];
        $lines[] = 'Живым считаем сообщение с лагом приёма меньше часа: оно попало к нам примерно тогда,';
        $lines[] = 'когда студент его отправил. Всё остальное — история, приехавшая задним числом.';
        $lines[] = '';
        $lines[] = '| День приёма | Живых | Дозабор | Медиана лага приёма | Самое старое `sent_at` в порции |';
        $lines[] = '|---|---:|---:|---:|---|';
        foreach ($byDay as $day => $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %s | %s |',
                $day,
                $row['live'],
                $row['backfill'],
                $this->humanMinutes($this->median($row['lags'])),
                $row['oldest']?->format('Y-m-d') ?? '—',
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  Collection<int, TelegramSupportMessage>  $incoming
     * @param  array<int, string>  $accounts
     * @return list<string>
     */
    private function accountSection($incoming, array $accounts): array
    {
        $byAccount = [];
        foreach ($incoming as $message) {
            $name = $accounts[$message->telegram_support_account_id] ?? ('#'.$message->telegram_support_account_id);
            $lag = $this->minutesBetween($message->sent_at, $message->created_at);
            $byAccount[$name] ??= ['n' => 0, 'live' => 0, 'lags' => []];
            $byAccount[$name]['n']++;
            $byAccount[$name]['lags'][] = $lag;
            if ($lag < self::LIVE_INGEST_LAG_MINUTES) {
                $byAccount[$name]['live']++;
            }
        }
        arsort($byAccount);

        $lines = ['## 2. По аккаунтам поддержки', ''];
        $lines[] = '| Аккаунт | Входящих | Живых | Медиана лага приёма |';
        $lines[] = '|---|---:|---:|---:|';
        foreach ($byAccount as $name => $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %s |',
                $name,
                $row['n'],
                $row['live'],
                $this->humanMinutes($this->median($row['lags'])),
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @param  Collection<int, TelegramSupportMessage>  $messageById
     * @return list<string>
     */
    private function staleBandSection($events, $messageById): array
    {
        $bands = $this->staleBands($events, $messageById);
        $total = array_sum($bands);

        $lines = ['## 3. Возраст сообщения в момент stale-пропуска', ''];
        $lines[] = 'Это ответ на «что именно мы пропускаем». Полоса 6–24 ч — единственное,';
        $lines[] = 'что вернул бы широкий потолок подсказок (A2); всё правее — история.';
        $lines[] = '';
        $lines[] = '| Возраст | Пропусков | Доля |';
        $lines[] = '|---|---:|---:|';
        foreach ($bands as $band => $count) {
            $lines[] = sprintf('| %s | %d | %s |', $band, $count, $total > 0 ? round(100 * $count / $total, 1).' %' : '—');
        }
        $lines[] = sprintf('| **всего** | **%d** | |', $total);
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array{stale: list<float>, hinted: list<float>, sent: list<float>}  $processing
     * @return list<string>
     */
    private function processingSection(array $processing): array
    {
        $lines = ['## 4. Лаг ОБРАБОТКИ: сколько тянули мы сами', ''];
        $lines[] = 'От попадания строки в базу до записи события. Здесь и только здесь видно';
        $lines[] = 'такт синка: если конвейер не успевает, эти числа велики.';
        $lines[] = '';
        $lines[] = '| Событие | Событий | Медиана | Максимум |';
        $lines[] = '|---|---:|---:|---:|';
        foreach (['stale' => 'stale-пропуск', 'hinted' => 'подсказка куратору', 'sent' => 'автоответ'] as $key => $label) {
            $values = $processing[$key];
            $lines[] = sprintf(
                '| %s | %d | %s | %s |',
                $label,
                count($values),
                $values === [] ? '—' : $this->humanMinutes($this->median($values)),
                $values === [] ? '—' : $this->humanMinutes(max($values)),
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Механический вердикт: причина выводится из чисел, а не из впечатления.
     *
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @param  Collection<int, TelegramSupportMessage>  $messageById
     * @param  array{stale: list<float>, hinted: list<float>, sent: list<float>}  $processing
     * @param  Collection<int, TelegramSupportMessage>  $incoming
     * @return list<string>
     */
    private function verdictSection($events, $messageById, array $processing, $incoming): array
    {
        $bands = $this->staleBands($events, $messageById);
        $total = array_sum($bands);
        $old = $bands['1–7 дн.'] + $bands['7–30 дн.'] + $bands['старше 30 дн.'];
        $recoverable = $bands['6–24 ч'];
        $medianProcessing = $processing['stale'] === [] ? 0.0 : $this->median($processing['stale']);

        $live = $incoming->filter(
            fn (TelegramSupportMessage $m): bool => $this->minutesBetween($m->sent_at, $m->created_at) < self::LIVE_INGEST_LAG_MINUTES
        )->count();

        if ($medianProcessing > self::CADENCE_BUG_PROCESSING_LAG_MINUTES) {
            $cause = 'ТАКТ СИНКА. Медиана лага обработки — '.$this->humanMinutes($medianProcessing)
                .', то есть сообщение лежит в базе необработанным дольше часа. Это наша задержка, её и надо чинить;'
                .' потолок свежести здесь ни при чём.';
        } elseif ($total > 0 && $old / $total >= self::BACKFILL_DOMINANT_SHARE) {
            $cause = 'ДОЗАБОР ИСТОРИИ ДИАЛОГОВ. Лаг обработки — '.$this->humanMinutes($medianProcessing)
                .' (конвейер успевает), а '.round(100 * $old / $total, 1).' % пропущенного старше суток.'
                .' Мы пропускаем не живые вопросы, а историю, которую MadelineProto подтягивает задним числом.'
                .' Такт синка исправен, чинить нечего; сам пропуск — правильное поведение.';
        } else {
            $cause = 'ПОЗДНЯЯ ДОСТАВКА. Обработка успевает, но пропущенное сосредоточено в свежих полосах —'
                .' смотрите на перебои приёма (перезапуски сессии, watchdog-убийства).';
        }

        $lines = ['## 5. Вердикт — причина stale-пропусков', ''];
        $lines[] = '**'.$cause.'**';
        $lines[] = '';
        $lines[] = sprintf(
            'Что вернёт расширение потолка подсказок до 24 ч: **%d** сообщений за окно из %d пропущенных (%s) —'
            .' полоса 6–24 ч и только она. Живых входящих за то же окно: **%d**.',
            $recoverable,
            $total,
            $total > 0 ? round(100 * $recoverable / $total, 1).' %' : '—',
            $live,
        );
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @param  Collection<int, TelegramSupportMessage>  $messageById
     * @return array<string, int>
     */
    private function staleBands($events, $messageById): array
    {
        $bands = ['меньше 6 ч' => 0, '6–24 ч' => 0, '1–7 дн.' => 0, '7–30 дн.' => 0, 'старше 30 дн.' => 0];

        foreach ($events as $event) {
            if ($event->event_type !== SupportDmAutoReply::EVENT_STALE_SKIP) {
                continue;
            }
            $message = $messageById->get($event->telegram_support_message_id);
            if ($message === null || $message->sent_at === null) {
                continue;
            }
            $hours = $this->minutesBetween($message->sent_at, $event->created_at) / 60;
            $band = match (true) {
                $hours < 6 => 'меньше 6 ч',
                $hours < 24 => '6–24 ч',
                $hours < 24 * 7 => '1–7 дн.',
                $hours < 24 * 30 => '7–30 дн.',
                default => 'старше 30 дн.',
            };
            $bands[$band]++;
        }

        return $bands;
    }

    /**
     * @param  Collection<int, SupportAiReplyEvent>  $events
     * @param  Collection<int, TelegramSupportMessage>  $messageById
     * @return array{stale: list<float>, hinted: list<float>, sent: list<float>}
     */
    private function processingLagMinutes($events, $messageById): array
    {
        $buckets = ['stale' => [], 'hinted' => [], 'sent' => []];

        foreach ($events as $event) {
            $key = match ($event->event_type) {
                SupportDmAutoReply::EVENT_STALE_SKIP => 'stale',
                SupportDmAutoReply::EVENT_HINTED => 'hinted',
                default => 'sent',
            };

            $message = $messageById->get($event->telegram_support_message_id);
            if ($message === null) {
                continue;
            }

            $buckets[$key][] = $this->minutesBetween($message->created_at, $event->created_at);
        }

        return $buckets;
    }

    private function minutesBetween(?CarbonInterface $from, ?CarbonInterface $to): float
    {
        if ($from === null || $to === null) {
            return 0.0;
        }

        return max(0.0, ($to->getTimestamp() - $from->getTimestamp()) / 60);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function humanMinutes(float $minutes): string
    {
        if ($minutes < 60) {
            return round($minutes, 1).' мин';
        }
        if ($minutes < 60 * 48) {
            return round($minutes / 60, 1).' ч';
        }

        return round($minutes / 1440, 1).' дн.';
    }
}
