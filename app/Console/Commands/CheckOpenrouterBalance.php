<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenrouterBalanceForecast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MG 24-08-2026 (Play A-2): OpenRouter balance + burn-rate forecast.
 * Daily tick stores a snapshot, projects the run-out date from our own
 * history, and two weeks before the projected zero asks for a top-up sized
 * to cover a full year with a safety factor.
 */
class CheckOpenrouterBalance extends Command
{
    protected $signature = 'openrouter:balance-check
        {--dry : Report only; do not store snapshot or send Telegram}';

    protected $description = 'OpenRouter balance, burn-rate forecast and yearly top-up recommendation.';

    public function handle(OpenrouterBalanceForecast $forecast): int
    {
        $result = $this->option('dry')
            ? $forecast->fetch()
            : $forecast->fetchAndStore();

        if (! $result['ok']) {
            $this->warn('OpenRouter skip-soft: '.$result['note']);

            return self::FAILURE;
        }

        $projection = $forecast->forecast();
        $remaining = (float) $result['remaining'];

        $this->table(['metric', 'value'], [
            ['остаток', '$'.number_format($remaining, 2)],
            ['расход/день', $projection['daily_avg'] !== null ? '$'.number_format($projection['daily_avg'], 2).' (за '.$projection['baseline_days'].' дн.)' : 'базовая линия собирается (<'.(int) config('openrouter.min_baseline_days', 7).' дн.)'],
            ['дней до исчерпания', $projection['days_left'] ?? '—'],
            ['дата исчерпания', $projection['runout_date'] ?? '—'],
        ]);

        if ($projection['daily_avg'] === null) {
            return self::SUCCESS;
        }

        if ($projection['days_left'] > (int) config('openrouter.alert_within_days', 14)) {
            return self::SUCCESS;
        }

        $topup = $forecast->suggestedTopup((float) $projection['daily_avg']);
        $this->warn('Порог '.(int) config('openrouter.alert_within_days', 14).' дн. достигнут: попросить пополнение на ≈$'.number_format($topup, 0));
        $this->alertTelegram($remaining, $projection, $topup);

        return self::FAILURE;
    }

    /**
     * @param  array{daily_avg: ?float, baseline_days: int, days_left: ?int, runout_date: ?string}  $projection
     */
    private function alertTelegram(float $remaining, array $projection, float $topup): bool
    {
        $dedupeKey = 'openrouter_balance_alert:'.now()->toDateString();
        if (Cache::get($dedupeKey)) {
            $this->comment('Дедуп '.$dedupeKey.' — повторный алерт не шлём.');

            return false;
        }

        $text = '<b>OpenRouter: деньги кончаются</b>'
            ."\nОстаток: $".number_format($remaining, 2)
            ."\nРасход: $".number_format((float) $projection['daily_avg'], 2).'/день (за '.$projection['baseline_days'].' дн.)'
            ."\nИсчерпание ≈ ".$projection['runout_date'].' ('.$projection['days_left'].' дн.)'
            ."\n\n<b>Попросить пополнение на ≈ $".number_format($topup, 0).'</b> (год вперёд ×'.config('openrouter.safety_factor', 1.25).')'
            ."\nПополнить: https://openrouter.ai/settings/credits";

        $token = (string) config('services.telegram.bot_token', '');
        $chatId = trim((string) config('openrouter.telegram_chat_id', ''));
        if ($token === '' || $chatId === '') {
            $this->warn('TELEGRAM_BOT_TOKEN или чат пусты — алерт не ушёл.');

            return false;
        }

        try {
            $response = Http::timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
            if ($response->successful() && ($response->json('ok') ?? false)) {
                Cache::put($dedupeKey, true, now()->addDays(4));
                $this->info('TG → '.$chatId);

                return true;
            }
            Log::warning('openrouter balance tg fail', ['body' => $response->body()]);
        } catch (Throwable $e) {
            Log::warning('openrouter balance tg error', ['error' => $e->getMessage()]);
        }

        return false;
    }
}
