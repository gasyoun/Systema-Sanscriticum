<?php

declare(strict_types=1);

namespace App\Support\MoneySli;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H4672 shared plumbing for both money-axis SLI commands
 * (money:sli-synthetic-pay, money:sli-hourly-reconcile):
 * Better Stack heartbeat ping (docs/UPTIME_BETTERSTACK_MONITORING.md
 * contract, same shape as ProbeCabinetHealth::reportToHealthchecks),
 * two-tier TG paging (money+prod ⇒ always critical chat, per MG ruling
 * 14-09-2026), and the money_sli_daily.tsv row writer.
 */
final class MoneySliAlerter
{
    public function __construct(private readonly MoneySliAlertState $state) {}

    public function heartbeat(string $pingUrl, bool $healthy, string $failSummary, bool $dry): void
    {
        $pingUrl = trim($pingUrl);
        if ($pingUrl === '') {
            return;
        }

        $target = $healthy ? $pingUrl : rtrim($pingUrl, '/').'/fail';
        $body = $healthy ? 'ok' : $failSummary;

        if ($dry) {
            Log::info('money_sli: --dry heartbeat, not sent', ['target' => $target]);

            return;
        }

        try {
            $response = Http::timeout((int) config('money_sli.http_timeout_seconds', 15))
                ->withBody($body, 'text/plain')
                ->post($target);

            if (! $response->successful()) {
                Log::warning('money_sli: heartbeat non-2xx', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('money_sli: heartbeat unreachable', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    public function alert(string $checkKey, string $heading, array $lines, bool $force, bool $dry): bool
    {
        $token = (string) config('services.telegram.bot_token', '');
        $chatIds = $this->parseChatIds(config('money_sli.telegram_chat_id', ''));
        if ($token === '' || $chatIds === []) {
            Log::warning('money_sli: TELEGRAM_BOT_TOKEN or money_sli.telegram_chat_id empty — TG skipped', [
                'check' => $checkKey,
            ]);

            return false;
        }

        $fingerprint = sha1($heading.'|'.implode('|', $lines));
        $reminderHours = max(0, (int) config('money_sli.telegram_reminder_hours', 4));

        if (! $this->state->shouldAlert($checkKey, $fingerprint, $reminderHours, $force)) {
            return false;
        }

        $text = "🚨 <b>{$heading}</b>\n\n".implode("\n", array_map(static fn (string $l): string => '• '.e($l), $lines))
            ."\n\nH4672 money-axis SLI · money-access-core-manual.md";

        if ($dry) {
            Log::info('money_sli: --dry TG, not sent', ['check' => $checkKey, 'text' => $text]);

            return false;
        }

        $sent = false;
        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout((int) config('money_sli.http_timeout_seconds', 15))
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if ($response->successful() && ($response->json('ok') ?? false)) {
                    $sent = true;
                } else {
                    Log::warning('money_sli: TG send failed', ['chat_id' => $chatId, 'body' => $response->body()]);
                }
            } catch (Throwable $e) {
                Log::warning('money_sli: TG send error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }

        if ($sent) {
            $this->state->recordSent($checkKey, $fingerprint);
        }

        return $sent;
    }

    public function recovered(string $checkKey): void
    {
        $this->state->clear($checkKey);
    }

    public function appendTsvRow(array $row): void
    {
        $path = (string) config('money_sli.tsv_path', storage_path('app/money_sli/money_sli_daily.tsv'));
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $isNew = ! is_file($path);
        $fh = @fopen($path, 'a');
        if ($fh === false) {
            Log::warning('money_sli: cannot open TSV for append', ['path' => $path]);

            return;
        }

        if ($isNew) {
            fputcsv($fh, array_keys($row), "\t");
        }
        fputcsv($fh, array_values($row), "\t");
        fclose($fh);
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
