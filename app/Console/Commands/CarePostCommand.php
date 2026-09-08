<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Support\CareChatReplyLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * H4362 / H4333 — постер в чат «Отдел заботы» ботом «Вестник».
 *
 * Бот: @samskrte_bot (MarketingSetting.tg_bot_token — расшифровывается моделью,
 * в лог/stdout никогда не попадает). Чат: recording_gap.care_telegram_chat_id
 * (RECORDING_GAP_CARE_TELEGRAM_CHAT_ID в .env). Текст — Telegram-HTML, превью
 * ссылок выключено, длиннее 4000 символов — режется по абзацам, хвосты идут
 * reply'ем на первый кусок. На stdout — одна JSON-строка с message_id первого
 * куска: его Uprava tools/guided_test_lane.py кладёт в леджер, чтобы потом
 * сопоставить ответы «готово / сломано» (care:replies).
 *
 *   php artisan care:post --file=/tmp/msg.html
 *   echo '<b>Привет</b>' | php artisan care:post
 *   php artisan care:post --file=… --dry-run   # показать, не отправлять
 */
final class CarePostCommand extends Command
{
    protected $signature = 'care:post
        {--file= : файл с HTML-текстом (по умолчанию читаем stdin)}
        {--dry-run : показать текст и адресата, ничего не отправлять}';

    protected $description = 'Постит HTML-сообщение в чат «Отдел заботы» ботом «Вестник» (H4362/H4333).';

    private const CHUNK = 4000;

    public function handle(): int
    {
        $text = $this->readText();
        if (trim($text) === '') {
            $this->error('Пустой текст: передайте --file=… или текст в stdin.');

            return self::FAILURE;
        }

        $chatId = CareChatReplyLog::careChatId();
        if ($chatId === '') {
            $this->error('RECORDING_GAP_CARE_TELEGRAM_CHAT_ID пуст — чат заботы не сконфигурирован.');

            return self::FAILURE;
        }

        $chunks = $this->chunk($text);

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'ok' => true,
                'dry_run' => true,
                'chat_id' => $chatId,
                'chunks' => count($chunks),
                'chars' => mb_strlen($text),
            ], JSON_UNESCAPED_UNICODE));
            foreach ($chunks as $i => $chunk) {
                $this->line('--- chunk '.($i + 1).' ---');
                $this->line($chunk);
            }

            return self::SUCCESS;
        }

        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            $this->error('MarketingSetting.tg_bot_token пуст — бот «Вестник» не сконфигурирован (FINDINGS §651).');

            return self::FAILURE;
        }

        $firstId = null;
        foreach ($chunks as $chunk) {
            $payload = [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($firstId !== null) {
                $payload['reply_to_message_id'] = $firstId;
            }
            $response = Http::timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', $payload);
            $ok = $response->successful() && (bool) ($response->json('ok') ?? false);
            if (! $ok) {
                $this->line(json_encode([
                    'ok' => false,
                    'chat_id' => $chatId,
                    'first_message_id' => $firstId,
                    'status' => $response->status(),
                    'error' => (string) ($response->json('description') ?? mb_substr($response->body(), 0, 300)),
                ], JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }
            $mid = (int) $response->json('result.message_id');
            if ($firstId === null) {
                $firstId = $mid;
            }
        }

        $this->line(json_encode([
            'ok' => true,
            'chat_id' => $chatId,
            'message_id' => $firstId,
            'chunks' => count($chunks),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function readText(): string
    {
        $file = (string) ($this->option('file') ?? '');
        if ($file !== '') {
            if (! is_file($file)) {
                return '';
            }

            return (string) file_get_contents($file);
        }

        $stdin = '';
        $handle = fopen('php://stdin', 'r');
        if ($handle !== false) {
            if (! stream_isatty($handle)) {
                $stdin = (string) stream_get_contents($handle);
            }
            fclose($handle);
        }

        return $stdin;
    }

    /**
     * Резать по абзацам (двойной перевод строки), не разрывая HTML-теги внутри
     * абзаца; абзац длиннее лимита — режем по строкам.
     *
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = str_replace("\r\n", "\n", trim($text));
        if (mb_strlen($text) <= self::CHUNK) {
            return [$text];
        }
        $units = [];
        foreach (preg_split('/\n{2,}/', $text) as $para) {
            if (mb_strlen($para) <= self::CHUNK) {
                $units[] = $para;

                continue;
            }
            foreach (explode("\n", $para) as $line) {
                $units[] = $line;
            }
        }
        $chunks = [];
        $cur = '';
        foreach ($units as $unit) {
            $candidate = $cur === '' ? $unit : $cur."\n\n".$unit;
            if (mb_strlen($candidate) > self::CHUNK && $cur !== '') {
                $chunks[] = $cur;
                $cur = $unit;
            } else {
                $cur = $candidate;
            }
        }
        if ($cur !== '') {
            $chunks[] = $cur;
        }

        return $chunks;
    }
}
