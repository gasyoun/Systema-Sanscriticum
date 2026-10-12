<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * H6546: рассылка копий видео по чатам курса (Bot API copyMessage от
 *
 * @zapisi_ORSbot — без плашки Forwarded from, подпись сохраняется) + черновик
 * админам (TelegramAdminNotifier — существующий ops-канал).
 *
 * Идемпотентность в два слоя: на каждый целевой чат свой клейм
 * video-anons:copy:{from}:{msg}:{target} (ретрай джоба не даёт вторую копию
 * в чате), на черновик свой video-anons:draft:{from}:{msg}. Клейм берётся
 * коротким («на время полёта») и продлевается после успеха — падение воркера
 * между клеймом и отправкой не хоронит сообщение на неделю.
 *
 * Отказ Telegram на ОДНОМ чате (например, бот не участник) не блокирует
 * остальные: ключ отпускается, отказ попадает в черновик и лог. Транспортный
 * обрыв ключ НЕ отпускает — доставка под вопросом, подавленный ретрай дешевле
 * дубля (контракт SendZapisiBotMessageJob).
 */
class DistributeVideoAnonsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Окно подавления дублей после успешной доставки (неделя — больше любого бэкоффа). */
    private const DONE_TTL = 604800;

    /** Клейм на время полёта одного прогона. */
    private const IN_FLIGHT_TTL = 300;

    public function __construct(
        public readonly int $sourceChatId,
        public readonly int $sourceMessageId,
        public readonly string $courseKey,
        public readonly array $targetChatIds,
        public readonly string $laneKey,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '') {
            Log::warning('VideoAnons: zapisi_bot_token is empty, distribution skipped', [
                'course' => $this->courseKey,
            ]);

            return;
        }

        $delivered = [];
        $failed = [];
        foreach ($this->targetChatIds as $target) {
            $target = (int) $target;
            $copyKey = sprintf('video-anons:copy:%d:%d:%d', $this->sourceChatId, $this->sourceMessageId, $target);

            if (! TelegramSendGuard::claimKey($copyKey, self::IN_FLIGHT_TTL)) {
                continue;
            }

            try {
                $response = Http::connectTimeout(5)->timeout(15)
                    ->post("https://api.telegram.org/bot{$token}/copyMessage", [
                        'chat_id' => $target,
                        'from_chat_id' => $this->sourceChatId,
                        'message_id' => $this->sourceMessageId,
                    ]);
            } catch (Throwable $exception) {
                Log::warning('VideoAnons: transport failure, retry suppressed to avoid duplicate', [
                    'target_chat_id' => $target,
                    'error' => $exception->getMessage(),
                ]);
                $failed[$target] = 'транспортный сбой, ретрай подавлен';

                continue;
            }

            if ($response->successful() && ($response->json('ok') ?? false)) {
                TelegramSendGuard::extendKey($copyKey, self::DONE_TTL);
                $delivered[$target] = (int) $response->json('result.message_id');

                continue;
            }

            // Telegram ответил отказом = доставлено не было; ключ отпускаем,
            // чтобы ретрай мог отправить (или чтобы отказ остался отказом).
            $this->releaseKey($copyKey);
            $failed[$target] = (string) ($response->json('description') ?? ('HTTP '.$response->status()));
            Log::warning('VideoAnons: Telegram declined copy', [
                'target_chat_id' => $target,
                'status' => $response->status(),
                'description' => $failed[$target],
            ]);
        }

        $this->notifyAdmins($token, $delivered, $failed);
    }

    /**
     * Черновик MG/админам (решение 1: «чаты курса — авто, всё остальное —
     * черновик со словом»). Сейчас «остальное» — сторис, и тот флаг off.
     *
     * @param  array<int, int>  $delivered  target => message_id копии
     * @param  array<int, string>  $failed  target => причина
     */
    private function notifyAdmins(string $token, array $delivered, array $failed): void
    {
        $draftKey = sprintf('video-anons:draft:%d:%d', $this->sourceChatId, $this->sourceMessageId);
        if (! TelegramSendGuard::claimKey($draftKey, self::IN_FLIGHT_TTL)) {
            return;
        }

        $lines = [];
        foreach ($delivered as $target => $copyMessageId) {
            $lines[] = '• <code>'.$target.'</code> → копия message_id '.$copyMessageId;
        }
        foreach ($failed as $target => $reason) {
            $lines[] = '• <code>'.$target.'</code> → НЕ доставлено: '.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        }

        $internalId = preg_replace('/^-100/', '', (string) $this->sourceChatId);
        $stories = config('video-anons.stories_enabled')
            ? 'Сторис: включены — ответьте на это сообщение заголовком для публикации.'
            : 'Сторис: флаг off (VIDEO_ANONS_STORIES_ENABLED) — публикация по заголовку начнётся после включения.';

        $text = "🎬 <b>Видео-анонс</b> — копия видео разослана по чатам курса\n"
            .'Автор видео в учебном чате, курс: '.htmlspecialchars($this->courseKey, ENT_QUOTES, 'UTF-8')."\n"
            .'Источник: <code>'.$this->sourceChatId.'</code>, сообщение '
            ."<a href=\"https://t.me/c/{$internalId}/{$this->sourceMessageId}\">{$this->sourceMessageId}</a>\n"
            .'Доставлено: '.count($delivered).'/'.count($this->targetChatIds)."\n"
            .implode("\n", $lines)."\n"
            .$stories;

        $notifier = app(TelegramAdminNotifier::class);
        $recipients = $notifier->notifyAdmins($text);

        if ($recipients === []) {
            // Черновик — обязательная часть лейна (решение 1): сетевой сбой
            // (живой случай 12-10-2026 — cURL 28, DNS-резолв > 2 с у
            // TelegramTransport) не имеет права терять его молча. Ключ
            // отпускаем и бросаем — бэкофф-ретрай джоба повторит ТОЛЬКО
            // черновик: копии уже держат недельные клейма и не задвоятся.
            $this->releaseKey($draftKey);

            throw new RuntimeException('VideoAnons: draft to admins not delivered, retry scheduled');
        }

        TelegramSendGuard::extendKey($draftKey, self::DONE_TTL);

        Log::info('VideoAnons: draft sent to admins', [
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
            'delivered' => array_keys($delivered),
            'failed' => array_keys($failed),
            'draft_recipients' => $recipients,
        ]);
    }

    private function releaseKey(string $key): void
    {
        try {
            Redis::del($key);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons: release failed (redis unavailable)', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('DistributeVideoAnonsJob failed permanently', [
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
