<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messaging\TelegramDeliveryChannel;
use App\Support\MarathonLandingCopy;
use App\Support\TelegramChannelEcho;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * H1067 publish step 2 — channel posts to @samskrte.
 *
 * Bot: MarketingSetting.tg_bot_token / tg_bot_username (magnet bot, usually
 *
 * @samskrte) — the same bot used for the Day 1–3 drip deep-link. That bot
 * must be an *administrator* of the channel with Post Messages permission.
 * Not the student-cabinet bot, not the zapisi booking bot.
 *
 * Default is dry-run. Pass --live to send.
 *
 * Idempotency (H1936): a `--live` send is recorded in `marathon_channel_posts_sent`
 * keyed by (post_number, run_key) before it can fire again — a fixed-date cron entry
 * has no other guard against a scheduler double-run or a redeploy re-triggering the
 * same minute. Post 3 (evergreen) uses the ISO year-week as its run_key so it still
 * re-fires every week; every other post uses the literal string 'once'.
 *
 * Cross-sender dedup (H3617): the TelegramChannelEcho sensor records every
 * channel_post the admin bot receives — including posts NOT sent by us
 * (Telegram-native scheduled messages, manual admin posts). A `--live` send
 * is refused when an identical text was echoed from the channel within the
 * last 24 h. Incident 28-08-2026: a Telegram-side scheduled message (10:00:05
 * MSK) and this cron (10:00:09) posted the same start text twice in four
 * seconds; no per-system guard can see the other system's send.
 */
final class PublishMarathonChannelPosts extends Command
{
    protected $signature = 'marathon:publish-channel-posts
                            {--post= : Post number 1–5 (all non-testimonial if omitted)}
                            {--live : Actually send (default: dry-run print only)}';

    protected $description = 'Publish H1067 @samskrte channel posts via magnet bot (dry-run by default)';

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        $chatId = (string) config('marathon_landing_copy.channel_chat_id', '@samskrte');
        $postOpt = $this->option('post');
        $live = (bool) $this->option('live');

        $numbers = $postOpt !== null && $postOpt !== ''
            ? [(int) $postOpt]
            : [1, 2, 3, 4]; // 5 only with testimonial — explicit --post=5

        $this->info($live ? 'LIVE send' : 'DRY-RUN (no Telegram API call)');
        $this->line("Channel chat_id: {$chatId}");
        $this->line('Bot: MarketingSetting.tg_bot_token (magnet / @samskrte drip bot)');

        $sent = 0;
        foreach ($numbers as $n) {
            $meta = MarathonLandingCopy::channelPost($n);
            if ($meta === null) {
                $this->error("Unknown post {$n}");

                return self::FAILURE;
            }

            if (! empty($meta['requires_testimonial']) && trim((string) config('marathon.testimonial', '')) === '') {
                $this->warn("Skip post {$n}: needs MARATHON_TESTIMONIAL (never invent).");

                continue;
            }

            try {
                $text = MarathonLandingCopy::resolvePostText($n);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("—— Post {$n} ({$meta['when']}) ——");
            $this->line($text);

            if (! $live) {
                continue;
            }

            $runKey = $this->runKeyFor($n);
            if ($this->alreadySent($n, $runKey)) {
                $this->warn("Skip post {$n}: already sent for run_key={$runKey}.");

                continue;
            }

            // H3617 — cross-sender дедуп: этот же текст уже пришёл эхом из
            // канала за последние 24 ч (запланированное в Telegram сообщение,
            // ручной пост админа) — второй копии не будет. Сравниваем сырой
            // разрешённый текст (Telegram возвращает channel_post.text без
            // разметки). Без markSent: в канал пост не ушёл, строка-registry
            // должна означать фактическую отправку.
            if (TelegramChannelEcho::seenRecently($chatId, $text)) {
                $this->warn("Skip post {$n}: identical text already in the channel within last 24 h (echo sensor, H3617 — cross-sender dedup).");

                continue;
            }

            try {
                $telegram->sendMessage($chatId, $this->toTelegramHtml($text));
                $this->markSent($n, $runKey);
                $sent++;
                $this->info("Sent post {$n}.");
            } catch (Throwable $e) {
                $this->error("Failed post {$n}: ".$e->getMessage());
                $this->comment(
                    'Typical causes: magnet bot token empty; bot not admin of the channel; '
                    .'wrong MARATHON_CHANNEL_CHAT_ID (use @samskrte or numeric -100…).'
                );

                return self::FAILURE;
            }
        }

        if ($live) {
            $this->info("Done. Sent {$sent} post(s).");
        } else {
            $this->warn('Dry-run complete. Re-run with --live after deploy + bot is channel admin.');
        }

        return self::SUCCESS;
    }

    /**
     * Channel posts are plain text with optional emoji; escape HTML for
     * TelegramDeliveryChannel parse_mode=HTML without turning newlines into <br>.
     */
    private function toTelegramHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 'once' for one-shot posts; the ISO year-week for the recurring evergreen
     * post 3, so a scheduled weekly run re-fires every week but a same-week
     * double-invocation (scheduler overlap, manual re-run) is still deduped.
     */
    private function runKeyFor(int $postNumber): string
    {
        return $postNumber === 3 ? Carbon::now()->isoFormat('GGGG-[W]WW') : 'once';
    }

    private function alreadySent(int $postNumber, string $runKey): bool
    {
        return DB::table('marathon_channel_posts_sent')
            ->where('post_number', $postNumber)
            ->where('run_key', $runKey)
            ->exists();
    }

    private function markSent(int $postNumber, string $runKey): void
    {
        DB::table('marathon_channel_posts_sent')->insert([
            'post_number' => $postNumber,
            'run_key' => $runKey,
            'sent_at' => Carbon::now(),
        ]);
    }
}
