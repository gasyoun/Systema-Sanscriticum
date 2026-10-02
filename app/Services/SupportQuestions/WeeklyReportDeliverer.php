<?php

namespace App\Services\SupportQuestions;

use App\Console\Commands\CarePostCommand;
use App\Models\MarketingSetting;
use App\Models\SupportQuestionWeeklyDelivery;
use App\Support\CareChatReplyLog;
use App\Support\TelegramSendGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Exactly-once доставка недельного отчёта в чат «Отдел заботы» (H5709).
 *
 * Леджер отделён от агрегатов: upsert снапшота пересчитывает цифры сколько
 * угодно раз, повторный ПОСТ запрещён. Протокол:
 *  1. клейм (state=claimed) ДО сетевого вызова;
 *  2. успех Telegram (ok=true, есть message_id из ответа API) —
 *     acknowledged: message_id и есть подтверждение доставки;
 *  3. dedup-гард care:post подавил (идентичный текст уже уходил) —
 *     acknowledged c suppress_reason=dedup_guard, второго поста нет;
 *  4. отказ Telegram (ok=false, но API ответил) — not_delivered, ручной
 *     повтор через --send возможен (доставка точно не случилась);
 *  5. обрыв/таймаут/крэш после попытки — unknown; слепой автоповтор
 *     ЗАПРЕЩЁН, только --reconcile=sent|not_sent по факту из чата/леджера.
 */
class WeeklyReportDeliverer
{
    /**
     * @return array{state: string, message_id: int|null, suppressed: bool, reason: string|null}
     *
     * @throws \RuntimeException при неоднозначном состоянии (требует reconciliation)
     */
    public function deliver(string $weekStart, string $html): array
    {
        // Конфиг-проверка ДО клейма: отсутствующий адресат — блокер, а не
        // «полузаклейменная» строка, которую потом пришлось бы чинить.
        if (CareChatReplyLog::careChatId() === '') {
            throw new \RuntimeException(
                'RECORDING_GAP_CARE_TELEGRAM_CHAT_ID is empty — care chat is not configured. '
                .'Missing destination configuration is a blocker, not permission to invent a recipient.'
            );
        }

        /** @var SupportQuestionWeeklyDelivery $row */
        $row = DB::transaction(function () use ($weekStart): SupportQuestionWeeklyDelivery {
            /** @var SupportQuestionWeeklyDelivery|null $row */
            $row = SupportQuestionWeeklyDelivery::query()
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->first();

            if ($row !== null) {
                if ($row->state === SupportQuestionWeeklyDelivery::STATE_ACKNOWLEDGED) {
                    return $row;
                }

                if (in_array($row->state, [
                    SupportQuestionWeeklyDelivery::STATE_CLAIMED,
                    SupportQuestionWeeklyDelivery::STATE_UNKNOWN,
                ], true)) {
                    throw new \RuntimeException(sprintf(
                        'Delivery for week %s is %s (claimed_at=%s): outcome unknown after an attempted send. '
                        .'Blind retry is forbidden — verify the chat/ledger and resolve with --reconcile=sent|not_sent.',
                        $weekStart,
                        $row->state,
                        $row->claimed_at?->toIso8601String() ?? 'n/a',
                    ));
                }
            } else {
                $row = new SupportQuestionWeeklyDelivery(['week_start' => $weekStart]);
            }

            $row->state = SupportQuestionWeeklyDelivery::STATE_CLAIMED;
            $row->claimed_at = now();
            $row->save();

            return $row;
        });

        if ($row->state === SupportQuestionWeeklyDelivery::STATE_ACKNOWLEDGED) {
            return [
                'state' => $row->state,
                'message_id' => $row->telegram_message_id,
                'suppressed' => true,
                'reason' => $row->suppress_reason ?? 'already_acknowledged',
            ];
        }

        return $this->attemptSend($row, $html);
    }

    /**
     * Ручное разрешение неоднозначной доставки по факту из чата/леджера.
     *
     * @return array{state: string, message_id: int|null}
     */
    public function reconcile(string $weekStart, bool $wasSent, ?int $messageId = null): array
    {
        /** @var SupportQuestionWeeklyDelivery $row */
        $row = SupportQuestionWeeklyDelivery::query()
            ->where('week_start', $weekStart)
            ->firstOrFail();

        $row->state = $wasSent
            ? SupportQuestionWeeklyDelivery::STATE_ACKNOWLEDGED
            : SupportQuestionWeeklyDelivery::STATE_NOT_DELIVERED;
        $row->telegram_message_id = $messageId ?? $row->telegram_message_id;
        $row->reconciled_at = now();
        if ($wasSent) {
            $row->suppress_reason = $row->suppress_reason ?? 'manual_reconcile';
        }
        $row->save();

        return ['state' => $row->state, 'message_id' => $row->telegram_message_id];
    }

    /**
     * Отправка повторяет контракт care:post (H4362): тот же чат, тот же токен
     * «Вестника», тот же TelegramSendGuard-клейм ДО вызова, нарезка по
     * абзацам тем же публичным chunk(). Вложенный Artisan::call здесь не
     * используется: внутри artisan-команды он подменяет выходной буфер
     * внешней команды (замерено H5709), и вызывающая команда теряет вывод.
     *
     * @return array{state: string, message_id: int|null, suppressed: bool, reason: string|null}
     */
    private function attemptSend(SupportQuestionWeeklyDelivery $row, string $html): array
    {
        $path = storage_path('app/support-questions/report-'.$row->week_start.'.html');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $html);

        $chatId = CareChatReplyLog::careChatId();
        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            $row->state = SupportQuestionWeeklyDelivery::STATE_NOT_DELIVERED;
            $row->meta = ['refused' => 'bot token empty'];
            $row->save();

            throw new \RuntimeException(
                'Weekly report delivery for '.(string) $row->week_start
                .': bot «Вестник» is not configured (MarketingSetting.tg_bot_token empty) — delivery did not happen.'
            );
        }

        $chunks = (new CarePostCommand)->chunk($html);
        $firstId = null;
        $suppressed = false;

        // Таймаут/обрыв после попытки отправки = unknown: исход неизвестен,
        // слепой повтор запрещён — только reconciliation по чату/леджеру.
        try {
            $this->sendChunks($row, $chatId, $token, $chunks, $firstId, $suppressed);
        } catch (ConnectionException $e) {
            $row->state = SupportQuestionWeeklyDelivery::STATE_UNKNOWN;
            $row->meta = ['connection' => mb_substr($e->getMessage(), 0, 200)];
            $row->save();

            throw new \RuntimeException(
                'Weekly report delivery for '.(string) $row->week_start
                .': send outcome UNKNOWN (connection lost after attempt) — reconcile with --reconcile before any retry.'
            );
        }

        $row->state = SupportQuestionWeeklyDelivery::STATE_ACKNOWLEDGED;
        $row->sent_at = now();
        $row->telegram_message_id = $firstId;
        if ($suppressed) {
            $row->suppress_reason = 'dedup_guard';
        }
        $row->meta = ['chunks' => count($chunks)];
        $row->save();

        return [
            'state' => $row->state,
            'message_id' => $row->telegram_message_id,
            'suppressed' => $suppressed,
            'reason' => $row->suppress_reason,
        ];
    }

    /**
     * Посылка чанков с семантикой care:post (claim → send → release при
     * отказе). Изменяет $firstId/$suppressed по ссылке. Отказ Telegram
     * помечает not_delivered и бросает; dedup-гард — тихое подавление.
     *
     * @param  list<string>  $chunks
     */
    private function sendChunks(
        SupportQuestionWeeklyDelivery $row,
        string $chatId,
        string $token,
        array $chunks,
        ?int &$firstId,
        bool &$suppressed,
    ): void {
        foreach ($chunks as $chunk) {
            if (! TelegramSendGuard::claim($chatId, $chunk)) {
                // Идентичный текст уже уходил в этот чат за окно TTL —
                // доставки НЕТ, но дубликат невозможен; считаем подавленным
                // подтверждением (exactly-once уже выполнен ранее).
                $suppressed = true;

                break;
            }

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
                TelegramSendGuard::release($chatId, $chunk);
                // Первый чанк уже мог уйти: отказ в СЕРЕДИНЕ последовательности
                // — исход частично неизвестен, а не «доставки не было».
                $row->state = $firstId !== null
                    ? SupportQuestionWeeklyDelivery::STATE_UNKNOWN
                    : SupportQuestionWeeklyDelivery::STATE_NOT_DELIVERED;
                $row->meta = [
                    'refused' => true,
                    'status' => $response->status(),
                    'error' => mb_substr((string) $response->json('description', ''), 0, 200),
                ];
                $row->save();

                throw new \RuntimeException(sprintf(
                    'Weekly report delivery for %s: Telegram refused the request (delivery did NOT happen) — retry with --send is safe.',
                    (string) $row->week_start,
                ));
            }

            $mid = (int) $response->json('result.message_id');
            if ($firstId === null) {
                $firstId = $mid;
            }
        }

    }
}
