<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Лог-сторож 500-класса (H4648, закрывает GTD 13-09 «Watcher: 500-класс по
 * маршрутам»).
 *
 * Инцидент 10–13.09.2026: фатал «CI green, прод 500» — 43 ошибки / 9 студентов /
 * ~3 дня — лежал в storage/logs/laravel-2026-09-10.log, и никто это не читал.
 * Эта команда читает за всех: сканирует СЕГОДНАШНИЙ daily-лог плюс вчерашний
 * до LOGS_WATCH_YESTERDAY_UNTIL_UTC (overlap через ротацию — ночной всплеск не
 * теряется, даже если сами ночные прогоны сторожа сорвались), группирует
 * production.ERROR по классу исключения + месту в стектрейсе и при всплеске
 * (≥ threshold_per_hour одинаковых находок в пределах одного часа) шлёт TG soft
 * в канал пробы.
 *
 * Анти-spam — семантика H2335 (cabinet:probe): один и тот же набор находок
 * (fingerprint класса уровня) молчит до зелёного прогона, reminder раз в
 * LOGS_WATCH_REMINDER_HOURS; новый набор алертит немедленно; чистый прогон
 * гасит состояние (sticky до зелёного).
 *
 * БЕЗОПАСНОСТЬ ПРОДА: команда НИЧЕГО не пишет в прод-данные — читает логи,
 * ведёт собственный state-файл в storage/app и шлёт TG. Без TG-канала в конфиге
 * — ГРОМКИЙ warning-блок в выводе (не тихий пропуск, класс слепого пятна
 * H3797 «пусто = пропуск»), алерт не считается отправленным.
 */
class WatchErrorLogs extends Command
{
    /** H2335-стиль: ключи state-файла. */
    private const STATE_LAST_ALERT_AT = 'last_alert_at';

    private const STATE_FP = 'fingerprint';

    protected $signature = 'logs:error-watch
        {--dry : Прогнать скан и показать находки, не слать TG и не писать state}
        {--force-alert : Игнорировать sticky/reminder и послать немедленно}';

    protected $description = 'Сторож 500-класса: всплеск production.ERROR в daily-логах → TG soft (H4648)';

    public function handle(): int
    {
        if (! (bool) config('logs_watch.enabled', true)) {
            $this->comment('logs_watch.enabled=false — сторож выключен (осознанно, не ошибка).');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $files = $this->scanFiles($now);

        if ($files === []) {
            $this->warn('⚠️ logs:error-watch: лог-файлы не найдены ('.$this->logDir().'/laravel-*.log) — сторож слепой. Это НЕ тихий пропуск: проверьте канал логирования (LOG_CHANNEL=daily на проде).');

            return self::SUCCESS;
        }

        $hits = $this->collectHits($files, $now);
        $threshold = max(1, (int) config('logs_watch.threshold_per_hour', 3));
        $groups = $this->groupBursts($hits, $threshold);

        if ($groups === []) {
            $this->info('Логи чисты: 0 всплесков ≥'.$threshold.'/ч за окно (сегодня + вчера до '
                .(string) config('logs_watch.yesterday_until_utc', '06:00').' UTC), файлов: '.count($files).'.');
            $this->stateClear();

            return self::SUCCESS;
        }

        $fingerprint = $this->fingerprint(array_keys($groups));
        $lines = [];
        foreach ($groups as $key => $peak) {
            $lines[] = '• '.$key.' — пик '.$peak.'/ч (порог '.$threshold.')';
        }

        $text = '🚨 <b>Прод: всплеск ошибок в логах</b> ('.count($groups)." кл.)\n\n"
            .implode("\n", array_slice($lines, 0, max(1, (int) config('logs_watch.max_alert_lines', 8))))."\n\n"
            .'<code>ssh root@193.232.229.92</code>'."\n"
            .'<code>php artisan logs:error-watch --dry</code>'."\n"
            .'<code>tail -n 100 storage/logs/laravel-'.now()->format('Y-m-d').'.log</code>';

        if ($this->option('dry')) {
            $this->comment('--dry: TG не шлём, state не пишем.');
            $this->line($text);

            return self::SUCCESS;
        }

        $chatIds = $this->parseChatIds((string) config('logs_watch.telegram_chat_id', ''));
        if ($chatIds === []) {
            // ГРОМКИЙ пропуск (H3797-класс): пустой канал — не тихий skip.
            $this->warn('⚠️⚠️⚠️ LOGS_WATCH_TELEGRAM_CHAT_ID ПУСТ — СТОРОЖ НЕ МОЖЕТ КРИЧАТЬ ⚠️⚠️⚠️');
            $this->warn('Обнаружен всплеск ошибок, но отправить алерт некуда:');
            foreach ($lines as $line) {
                $this->warn('   '.$line);
            }
            $this->warn('Быстрый алерт ПРОПУЩЕН (state не ставится — следующий прогон с каналом пошлёт).');
            $this->warn('Задать канал: .env LOGS_WATCH_TELEGRAM_CHAT_ID=<chat_id> && php artisan config:cache');

            return self::SUCCESS;
        }

        if (! $this->option('force-alert') && $this->stateSticky($fingerprint)) {
            return self::SUCCESS;
        }

        if ($this->sendTelegram($chatIds, $text)) {
            $this->statePut([self::STATE_LAST_ALERT_AT => now(), self::STATE_FP => $fingerprint]);
        }

        return self::SUCCESS;
    }

    /**
     * Файлы скана: сегодня всегда, вчера — до cutoff UTC (overlap ротации).
     *
     * @return list<array{path: string, until: ?CarbonInterface}>
     */
    private function scanFiles(CarbonInterface $now): array
    {
        $files = [];
        $today = $this->logDir().'/laravel-'.$now->format('Y-m-d').'.log';
        if (is_file($today)) {
            $files[] = ['path' => $today, 'until' => null];
        }

        $cutoff = trim((string) config('logs_watch.yesterday_until_utc', '06:00'));
        $yesterday = $this->logDir().'/laravel-'.$now->copy()->subDay()->format('Y-m-d').'.log';
        if ($cutoff !== '' && $cutoff !== '0' && is_file($yesterday)) {
            // Cutoff трактуется в UTC: «вчерашнего до 06:00 UTC» из H4648.
            $until = Carbon::parse($now->copy()->subDay()->format('Y-m-d').' '.$cutoff, 'UTC')->timezone($now->getTimezone());
            $files[] = ['path' => $yesterday, 'until' => $until];
        }

        return $files;
    }

    private function logDir(): string
    {
        $dir = trim((string) config('logs_watch.log_dir', ''));

        return $dir !== '' ? $dir : storage_path('logs');
    }

    /**
     * Все ERROR-строки окна: parse заголовка Laravel-лога + класс исключения +
     * место (стектрейс). Строки без [object] (простые Log::error) считаются
     * отдельным классом «log-error» с местом из сообщения.
     *
     * @param  list<array{path: string, until: ?CarbonInterface}>  $files
     * @return list<array{ts: CarbonInterface, class: string, place: string}>
     */
    private function collectHits(array $files, CarbonInterface $now): array
    {
        $environments = (array) config('logs_watch.environments', ['production']);
        $levels = (array) config('logs_watch.levels', ['ERROR']);

        $header = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Za-z0-9._-]+)\.([A-Z]+):\s(.*)$/';
        $object = '/\[object\]\s*\(([A-Za-z0-9_\\\\]+)\(code:\d+\).*? at (.+?\.php):(\d+)/';

        $hits = [];
        foreach ($files as ['path' => $path, 'until' => $until]) {
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            try {
                while (($raw = fgets($handle)) !== false) {
                    $line = rtrim($raw, "\r\n");
                    if ($line === '' || $line[0] !== '[' || ! preg_match($header, $line, $m)) {
                        continue;
                    }
                    if (! in_array($m[2], $environments, true) || ! in_array($m[3], $levels, true)) {
                        continue;
                    }
                    $ts = Carbon::createFromFormat('Y-m-d H:i:s', $m[1], $now->getTimezone());
                    if ($ts === false) {
                        continue;
                    }
                    if ($until !== null && $ts->gt($until)) {
                        continue;
                    }

                    $class = 'log-error';
                    $place = '?';
                    if (preg_match($object, $m[4], $e)) {
                        // В файле JSON экранирует бэкслеши: App\\Models\\X → App\Models\X.
                        $class = str_replace('\\\\', '\\', $e[1]);
                        $place = $this->placeLabel(str_replace('\\\\', '\\', $e[2]), $e[3]);
                    } else {
                        $place = mb_substr(trim($m[4]), 0, 80);
                    }

                    $hits[] = ['ts' => $ts, 'class' => $class, 'place' => $place];
                }
            } finally {
                fclose($handle);
            }
        }

        return $hits;
    }

    /**
     * Метка места — грубый «маршрут»: controller-кадр стектрейса точнее всего
     * указывает на поверхность (инцидент 10-13.09: StudentController.php).
     * Строки стектрейса не включаем в группу (шум), только файл.
     */
    private function placeLabel(string $file, string $lineNo): string
    {
        if (preg_match('#/app/Http/(Controllers|Middleware|Requests)/([A-Za-z0-9_/]+)\.php$#', $file, $m)) {
            return $m[1].'\\'.str_replace('/', '\\', $m[2]);
        }

        return basename($file).':'.$lineNo;
    }

    /**
     * Группы-всплески: (класс + место) → максимум ошибок в пределах одного
     * часового ковша. Ковш = клок-час (приложение, Europe/Moscow).
     *
     * @param  list<array{ts: CarbonInterface, class: string, place: string}>  $hits
     * @return array<string, int> ключ группы → пик в часе (только группы ≥ порога)
     */
    private function groupBursts(array $hits, int $threshold): array
    {
        $counts = [];
        foreach ($hits as $hit) {
            $key = $hit['class'].' @ '.$hit['place'];
            $bucket = $hit['ts']->format('Y-m-d H');
            $counts[$key][$bucket] = ($counts[$key][$bucket] ?? 0) + 1;
        }

        $bursts = [];
        foreach ($counts as $key => $buckets) {
            $peak = max($buckets);
            if ($peak >= $threshold) {
                $bursts[$key] = $peak;
            }
        }
        ksort($bursts);

        return $bursts;
    }

    /**
     * Fingerprint НАБОРА групп (без часов/пиков): стабильный класс уровня —
     * тот же набор не пересылается (H2335), изменившийся — алертит сразу.
     *
     * @param  list<string>  $keys
     */
    private function fingerprint(array $keys): string
    {
        $normalized = array_values(array_unique($keys));
        sort($normalized);

        return hash('sha256', implode("\n", $normalized));
    }

    /**
     * Sticky до зелёного + reminder (H2335). true = «молчим, всё сказано».
     */
    private function stateSticky(string $fingerprint): bool
    {
        $state = $this->stateRead();
        $lastAt = $state[self::STATE_LAST_ALERT_AT] ?? null;
        $lastFp = $state[self::STATE_FP] ?? null;

        if (! is_string($lastFp) || $lastFp !== $fingerprint || ! is_string($lastAt) || $lastAt === '') {
            return false; // новый набор (или state потерян) — алертим
        }

        $reminderHours = max(0, (int) config('logs_watch.reminder_hours', 24));
        if ($reminderHours === 0) {
            $this->comment('sticky: тот же класс, без re-alert до зелёного (reminder=0)');

            return true;
        }

        try {
            $elapsedH = (int) now()->diffInHours(Carbon::parse($lastAt), absolute: true);
        } catch (Throwable) {
            return false;
        }
        if ($elapsedH < $reminderHours) {
            $this->comment('sticky: ~'.($reminderHours - $elapsedH).' ч до reminder (тот же класс ошибок)');

            return true;
        }

        $this->comment('reminder: тот же класс висит '.$elapsedH.' ч — напоминаем');

        return false;
    }

    private function stateClear(): void
    {
        $path = $this->statePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function statePut(array $values): void
    {
        $data = $this->stateRead();
        foreach ($values as $k => $v) {
            $data[$k] = $v instanceof CarbonInterface ? $v->toIso8601String() : $v;
        }
        $this->stateWrite($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stateRead(): array
    {
        $path = $this->statePath();
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stateWrite(array $data): void
    {
        $path = $this->statePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }
        @file_put_contents($path, $json, LOCK_EX);
    }

    private function statePath(): string
    {
        $configured = trim((string) config('logs_watch.state_path', ''));

        return $configured !== '' ? $configured : storage_path('app/logs_error_watch_state.json');
    }

    /**
     * @param  list<string>  $chatIds
     */
    private function sendTelegram(array $chatIds, string $text): bool
    {
        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '') {
            // Канал указан, бота нет — тоже громко (не тихий skip).
            $this->warn('⚠️ TELEGRAM_BOT_TOKEN пуст — алерт не отправлен (канал задан, бота нет).');

            return false;
        }

        $any = false;
        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout((int) config('logs_watch.timeout', 15))
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if ($response->successful() && ($response->json('ok') ?? false)) {
                    $any = true;
                    $this->info('TG → '.$chatId);
                } else {
                    $this->warn('TG не принят (HTTP '.$response->status().') — state не ставится, следующий прогон повторит.');
                }
            } catch (Throwable $e) {
                $this->warn('TG недоступен: '.$e->getMessage().' — state не ставится, следующий прогон повторит.');
            }
        }

        return $any;
    }

    /**
     * @return list<string>
     */
    private function parseChatIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('strval', $parts), static fn (string $id): bool => $id !== ''));
    }
}
