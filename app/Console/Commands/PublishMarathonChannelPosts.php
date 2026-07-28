<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messaging\TelegramDeliveryChannel;
use App\Support\MarathonLandingCopy;
use Illuminate\Console\Command;
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

            try {
                $telegram->sendMessage($chatId, $this->toTelegramHtml($text));
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
}
