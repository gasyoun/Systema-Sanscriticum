<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Models\StoryPost;
use App\Models\TelegramSupportAccount;
use App\Services\Stories\StoryPublisher;
use App\Services\Stories\StoryRepeatEngine;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncPhase;
use App\Services\Telegram\MadelineSyncWatchdog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Издатель user-сториз персоны @rusamskrtam (H3964, Phase 2).
 *
 * Берёт approved+due строки lane=persona (kind text|photo|video) и кладёт
 * сториз на СВОЙ профиль аккаунта через MadelineProto (рулинг MG
 * «персона + канал»: сториз живёт на persona-аккаунте, БЕЗ админ-прав
 * канала; текстовые посты канала — отдельный stories:publish-due, Phase 1).
 *
 * Сессия ОДНА на всё (легаси support-сессия и есть @rusamskrtam, H3380):
 * живой проход открывает её только под madeline-session-локом и никогда
 * параллельно с telegram-support:sync / telegram-harvest:* (AUTH_RESTART),
 * TTL лока выводится madelineSessionLockMinutes() из stories_timeout_seconds.
 *
 * Студенческие медиа (source=homework) гейтятся визой MG на правило
 * анонимизации (features.telegram_story_student_media_visa, default OFF):
 * до визы строка скипается с журналом, НИКОГДА не публикуется
 * (ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026 §2.8).
 *
 * Прод-инертен: features.telegram_story_stories OFF (default) → ранний
 * возврат, ноль HTTP, MadelineProto-сессия не открывается вовсе.
 *
 * Режимы:
 *   --test-text="..."   одна тестовая текстовая сториз: отправлена и тут же
 *                       удалена тем же кодом (смок/проба сессии; --keep
 *                       оставляет её висеть до конца суток).
 *   --probe-attempts=N  (только вместе с --test-text) до N дополнительных
 *                       отправок send→delete — снять фактический дневной
 *                       лимит user-сториз по первому FLOOD-коду. Потолок 30
 *                       за прогон: аккаунт общий с поддержкой, штормить его
 *                       нельзя.
 *   --delete-story=ID   удалить свою сториз по id (уборка артефактов).
 */
final class StoriesPublishStoryCommand extends Command
{
    use LocksMadelineSession;

    /** Жёсткий потолок пробы лимита за один прогон. */
    private const PROBE_CAP = 30;

    protected $signature = 'stories:publish-story
        {--test-text= : Отправить одну тестовую текстовую сториз и удалить её тем же кодом}
        {--keep : Не удалять тестовую сториз (--test-text)}
        {--probe-attempts=0 : Дослать ещё до N сториз (send→delete) до первого FLOOD — замер дневного лимита}
        {--delete-story= : Удалить свою сториз по id}';

    protected $description = 'Publish due approved story_posts (lane=persona) as user-stories of @rusamskrtam via MadelineProto';

    public function handle(
        StoryPublisher $publisher,
        StoryRepeatEngine $repeat,
        MadelineClientFactory $factory,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        if (! config('features.telegram_story_stories')) {
            $this->warn('telegram_story_stories flag is OFF — no-op.');

            return self::SUCCESS;
        }

        if (! $factory->isConfigured()) {
            $this->error('MadelineProto is not configured (services.telegram_support.api_id/api_hash/client_class).');

            return self::FAILURE;
        }

        // Режимы --test-text / --delete-story открывают сессию всегда; основной
        // проход — ТОЛЬКО когда очередь реально непуста: сессия одна на
        // поддержку+харвест, гонять её демон ежечасно ради пустого запроса
        // нельзя (запуск клиента ~40 с жизни общего аккаунта).
        $isQueueRun = $this->option('test-text') === null && $this->option('delete-story') === null;
        if ($isQueueRun
            && StoryPost::query()->approved()->due()->lane(StoryPost::LANE_PERSONA)->count() === 0) {
            $this->info('publish-story: очередь persona пуста — MadelineProto не открывается.');

            return self::SUCCESS;
        }

        $timeout = (int) config('services.telegram_story.stories_timeout_seconds', 120);
        $cooldown = (int) config('services.telegram_harvest.sync_timeout_cooldown_seconds', 600);

        // Та же сессия, что и у support/harvest — пост-таймаутный cooldown,
        // вооружённый ЛЮБЫМ из них, обязан гасить и этот лейн (H3411-форма).
        if ($cooldown > 0 && MadelineSyncPhase::cooldownActive()) {
            Log::warning('Stories publish skipped: post-timeout cooldown', [
                'cooldown_seconds' => $cooldown,
                'last_phase' => MadelineSyncPhase::current(),
            ]);
            $this->warn('MadelineProto session in post-timeout cooldown — skipped.');

            return self::SUCCESS;
        }

        $armed = $watchdog->arm($timeout, fn (int $seconds) => $this->cleanUpAfterTimeout($reaper, $seconds));
        if (! $armed && $timeout > 0) {
            Log::error('Stories publish running WITHOUT a time ceiling: watchdog did not arm (pcntl missing).', [
                'timeout_seconds' => $timeout,
            ]);
            $this->warn('Watchdog unavailable (pcntl extension missing) — running without a time ceiling.');
        }

        try {
            $exit = $this->withMadelineSessionLock(
                fn (): int => $this->runLane($publisher, $repeat),
                // Сториз-лейн не конкурирует со слотом, а дожидается СВОЕГО
                // окна: минутный support:sync держит лок ~40 с за прогон,
                // 90 с ожидания перекрывают его, не съедая потолок watchdog'а.
                90,
            );
        } finally {
            $watchdog->disarm();
        }

        if ($exit === null) {
            Log::warning('Stories publish skipped: MadelineProto session busy.');
            $this->warn('MadelineProto session busy (support/harvest holds the lock) — skipped.');

            return self::FAILURE;
        }

        return $exit;
    }

    private function runLane(StoryPublisher $publisher, StoryRepeatEngine $repeat): int
    {
        if ($deleteId = $this->option('delete-story')) {
            $publisher->deleteStory((int) $deleteId);
            $this->info("Deleted story #{$deleteId}.");

            return self::SUCCESS;
        }

        if (($testText = $this->option('test-text')) !== null) {
            return $this->runTestText($publisher, (string) $testText);
        }

        return $this->publishDue($publisher, $repeat);
    }

    /** Тестовая сториз: отправлена и удалена тем же кодом + ограниченная проба лимита. */
    private function runTestText(StoryPublisher $publisher, string $text): int
    {
        $attempts = min(max((int) $this->option('probe-attempts'), 0), self::PROBE_CAP);

        $this->info('Sending test text story…');
        $storyId = $publisher->sendTextStory($text);
        $this->info($storyId !== null ? "Sent story id={$storyId}." : 'Sent, but story id was not extractable from the Updates.');

        if ($storyId !== null && ! $this->option('keep')) {
            $publisher->deleteStory($storyId);
            $this->info("Deleted story id={$storyId} (same code path).");
        }

        $sent = 1;
        $flood = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $probeId = $publisher->sendTextStory($text.' (probe '.($i + 1).'/'.self::PROBE_CAP.')');
            } catch (Throwable $e) {
                if ($this->looksLikeFlood($e)) {
                    $flood = $e->getMessage();
                } else {
                    $this->error('Probe attempt failed: '.$e->getMessage());

                    return self::FAILURE;
                }
                break;
            }

            $sent++;
            if ($probeId !== null) {
                $publisher->deleteStory($probeId);
            }
        }

        $this->info($flood !== null
            ? "Daily limit probe: FLOOD after {$sent} send(s): {$flood}"
            : "Daily limit probe: no FLOOD within the bounded cap ({$sent} send(s), cap "
                .self::PROBE_CAP.", attempts={$attempts}).");

        return self::SUCCESS;
    }

    private function looksLikeFlood(Throwable $e): bool
    {
        return (bool) preg_match('/FLOOD|flood/i', $e->getMessage());
    }

    /** Основной проход: approved+due, lane=persona, kinds text|photo|video. */
    private function publishDue(StoryPublisher $publisher, StoryRepeatEngine $repeat): int
    {
        $visa = (bool) config('features.telegram_story_student_media_visa');

        $due = StoryPost::query()
            ->approved()
            ->due()
            ->lane(StoryPost::LANE_PERSONA)
            ->whereIn('kind', [StoryPost::KIND_TEXT, StoryPost::KIND_PHOTO, StoryPost::KIND_VIDEO])
            ->orderBy('publish_at')
            ->get();

        $published = 0;
        $skipped = 0;
        foreach ($due as $post) {
            if ($post->source === StoryPost::SOURCE_HOMEWORK && ! $visa) {
                $this->journalSkip($post, 'студенческое медиа: виза MG на правило анонимизации не выдана '
                    .'(features.telegram_story_student_media_visa OFF, ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026 §2.8).');
                $skipped++;
                $this->warn("Skip #{$post->id}: student media without anonymization visa.");

                continue;
            }

            if (in_array($post->kind, [StoryPost::KIND_PHOTO, StoryPost::KIND_VIDEO], true)) {
                $path = (string) $post->media_path;
                if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                    $post->forceFill([
                        'status' => StoryPost::STATUS_SKIPPED,
                        'journal' => trim((string) $post->journal."\n".now()->toDateTimeString()
                            ." skip: медиа-файл не читается ({$path}) — строка выведена из очереди."),
                    ])->save();
                    $skipped++;
                    $this->warn("Skip #{$post->id}: media file missing/unreadable ({$path}).");

                    continue;
                }
            }

            try {
                $storyId = $this->sendPost($publisher, $post);
            } catch (Throwable $e) {
                $this->error("Failed #{$post->id}: ".$e->getMessage());
                $post->forceFill([
                    'journal' => trim((string) $post->journal."\n".now()->toDateTimeString()
                        .' error: '.$e->getMessage()),
                ])->save();

                return self::FAILURE;
            }

            $repeat->markPublished($post, $storyId !== null ? 'story:'.$storyId : null);
            $copy = $repeat->afterPublication($post);
            $published++;
            $this->info("Published story #{$post->id} (kind={$post->kind}, source={$post->source})"
                .($copy !== null ? " + repeat copy #{$copy->id} due {$copy->publish_at?->format('d-m-Y H:i')}" : '.'));
        }

        $this->info("publish-story: published={$published}, skipped={$skipped}.");

        return self::SUCCESS;
    }

    private function sendPost(StoryPublisher $publisher, StoryPost $post): ?int
    {
        $caption = (string) $post->payload;

        return match ($post->kind) {
            StoryPost::KIND_TEXT => $publisher->sendTextStory($caption),
            StoryPost::KIND_PHOTO => $publisher->sendPhotoStory((string) $post->media_path, $caption),
            StoryPost::KIND_VIDEO => $publisher->sendVideoStory((string) $post->media_path, $caption),
            default => throw new \InvalidArgumentException("Unsupported story kind {$post->kind}."),
        };
    }

    /** Журнальный скип без смены статуса: строка остаётся на кураторе. */
    private function journalSkip(StoryPost $post, string $reason): void
    {
        $post->forceFill([
            'journal' => trim((string) $post->journal."\n".now()->toDateTimeString().' skip: '.$reason),
        ])->save();
    }

    /**
     * Внутри SIGALRM-обработчика, прямо перед exit() (форма
     * SyncTelegramSupport/SyncTelegramHarvest): сначала лок, потом демон
     * сессии, потом кулдаун и след в БД.
     */
    private function cleanUpAfterTimeout(MadelineSessionReaper $reaper, int $seconds): void
    {
        $this->releaseMadelineSessionLock();

        $killed = $reaper->killDaemons();
        $removed = $reaper->clearIpcArtifacts();

        $cooldown = (int) config('services.telegram_harvest.sync_timeout_cooldown_seconds', 600);
        MadelineSyncPhase::armCooldown($cooldown);

        Log::error('Stories publish timed out — process stopped by watchdog', [
            'timeout_seconds' => $seconds,
            'killed_processes' => $killed,
            'removed_files' => $removed,
            'phase' => MadelineSyncPhase::current(),
            'cooldown_seconds' => $cooldown,
        ]);

        TelegramSupportAccount::query()
            ->where('name', 'support')
            ->update([
                'last_sync_error' => "stories:publish-story aborted on timeout ({$seconds}s); session daemon reset.",
            ]);

        $this->error("stories:publish-story: timeout after {$seconds}s — process stopped, session daemon reset.");
    }
}
