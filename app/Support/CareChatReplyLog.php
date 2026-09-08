<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * H4362 — журнал ответов в чате «Отдел заботы» (recording_gap.care_telegram_chat_id).
 *
 * Бот «Вестник» (@samskrte_bot, MarketingSetting.tg_bot_token) постит в «Заботу»
 * ежедневный «тест-на-30-минут» (care:post). Команда отвечает на пост словами
 * «готово» / «сломано: …» — Reply на сообщение бота. При включённом privacy-mode
 * бот получает из группы только ответы на СВОИ сообщения и команды, поэтому
 * ловим ровно reply_to_message в чате заботы и пишем строку JSONL в
 * storage/app/care_chat_replies.jsonl. Читатель — care:replies (--since=…),
 * дальше Uprava tools/guided_test_lane.py сверяет reply_to_message_id
 * с леджером docs/guided-tests/LEDGER.md.
 *
 * Никаких таблиц и миграций: файл append-only, строки маленькие, чат — рабочий
 * (сотрудники, не студенты). Ничего не отвечаем в чат отсюда.
 */
final class CareChatReplyLog
{
    public const PATH = 'care_chat_replies.jsonl';

    public static function careChatId(): string
    {
        return trim((string) config('recording_gap.care_telegram_chat_id', ''));
    }

    /**
     * Записать ответ, если апдейт — reply в чате заботы. true = записали
     * (и апдейт дальше обрабатывать не нужно), false = не наш случай.
     */
    public static function captureFromUpdate(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $careId = self::careChatId();
        $chatId = $message['chat']['id'] ?? null;
        if ($careId === '' || $chatId === null || (string) $chatId !== $careId) {
            return false;
        }

        $replyTo = $message['reply_to_message'] ?? null;
        if (! is_array($replyTo) || ! isset($replyTo['message_id'])) {
            return false;
        }

        $text = $message['text'] ?? ($message['caption'] ?? '');
        $from = $message['from'] ?? [];
        $row = [
            'captured_at' => Carbon::now('UTC')->toIso8601String(),
            'date' => isset($message['date']) ? (int) $message['date'] : null,
            'chat_id' => (string) $chatId,
            'message_id' => (int) $message['message_id'],
            'reply_to_message_id' => (int) $replyTo['message_id'],
            'reply_to_from_id' => isset($replyTo['from']['id']) ? (int) $replyTo['from']['id'] : null,
            'reply_to_is_bot' => (bool) ($replyTo['from']['is_bot'] ?? false),
            'from_id' => isset($from['id']) ? (int) $from['id'] : null,
            'from_username' => $from['username'] ?? null,
            'from_first_name' => $from['first_name'] ?? null,
            'text' => (string) $text,
        ];

        Storage::disk('local')->append(self::PATH, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }

    /**
     * Прочитать журнал; since — ISO-время (UTC) по captured_at, строго позже.
     *
     * @return list<array<string,mixed>>
     */
    public static function read(?string $since = null, int $limit = 500): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::PATH)) {
            return [];
        }
        $sinceTs = $since !== null && $since !== '' ? Carbon::parse($since)->getTimestamp() : null;
        $rows = [];
        foreach (preg_split('/\r?\n/', (string) $disk->get(self::PATH)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            if ($sinceTs !== null) {
                $ts = isset($row['captured_at']) ? Carbon::parse((string) $row['captured_at'])->getTimestamp() : 0;
                if ($ts <= $sinceTs) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        return array_slice($rows, -$limit);
    }
}
