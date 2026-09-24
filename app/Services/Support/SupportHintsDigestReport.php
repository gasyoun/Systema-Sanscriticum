<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;

/**
 * H5452: ежедневный дайджест открытых подсказок куратору — «Ждут ответа:
 * N чатов / M сообщений; старейший — X ч; 🔥: K». Компаньон недельного
 * разбора H3392: между воскресеньями канал не должен неделями молчать.
 *
 * Открытый хинт = последний dm_hinted чата, после которого в чате не было
 * НИ ОДНОГО human-исходящего (тот же предикат, что снимает дедуп и ack).
 * Число сообщений = сумма счётчиков серии из мет (H5452-дедуп). Имён
 * студентов нет: chat id / title / username — по ним куратор находит чат
 * в Telegram. Пустая очередь = ЯВНАЯ строка «очередь пуста», не молчание.
 */
final class SupportHintsDigestReport
{
    /**
     * @return array{
     *     chats: int,
     *     messages: int,
     *     fire: int,
     *     oldest_hours: int|null,
     *     rows: list<array{chat_id: int, label: string, hours: int, messages: int, fire: bool}>,
     *     text: string
     * }
     */
    public function build(int $days = 30): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $now = CarbonImmutable::now($tz);

        $events = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_HINTED)
            ->where('created_at', '>=', $now->subDays(max(1, $days)))
            ->orderBy('id')
            ->get(['id', 'telegram_support_message_id', 'meta', 'created_at']);

        // События не несут chat_id в мете (H5452 и младше) — маппим одно
        // запросом: message id → telegram_chat_id.
        $chatByMessage = TelegramSupportMessage::query()
            ->whereIn('id', $events->pluck('telegram_support_message_id')->filter()->unique())
            ->pluck('telegram_chat_id', 'id');

        // Последнее событие на чат: события упорядочены по id — поздний
        // перезаписывает ранний.
        $latestByChat = [];

        foreach ($events as $event) {
            $chatId = $chatByMessage[$event->telegram_support_message_id] ?? null;

            if ($chatId !== null) {
                $latestByChat[(int) $chatId] = $event;
            }
        }

        $open = [];

        foreach ($latestByChat as $chatId => $event) {
            if (! $this->humanAnsweredAfter($chatId, $event->created_at)) {
                $open[$chatId] = $event;
            }
        }

        $labels = TelegramSupportChat::query()
            ->whereIn('telegram_chat_id', array_keys($open))
            ->get(['telegram_chat_id', 'title', 'username'])
            ->keyBy('telegram_chat_id');

        $rows = [];
        $messages = 0;
        $fire = 0;
        $oldestHours = null;

        foreach ($open as $chatId => $event) {
            $meta = is_array($event->meta) ? $event->meta : [];
            $series = max(1, (int) ($meta['series_count'] ?? 1));
            $isFire = (bool) ($meta['fire'] ?? false);
            $hours = (int) round($event->created_at->diffInHours($now));

            $messages += $series;

            if ($isFire) {
                $fire++;
            }

            $oldestHours = $oldestHours === null ? $hours : max($oldestHours, $hours);

            /** @var TelegramSupportChat|null $chat */
            $chat = $labels->get($chatId);
            $label = $chat !== null
                ? (trim((string) $chat->title) !== '' ? (string) $chat->title : '@'.(string) $chat->username)
                : '';
            $label = trim($label) !== '' ? $label : 'чат '.$chatId;

            $rows[] = [
                'chat_id' => $chatId,
                'label' => $label,
                'hours' => $hours,
                'messages' => $series,
                'fire' => $isFire,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['fire'], $b['hours']] <=> [$a['fire'], $a['hours']]);

        return [
            'chats' => count($open),
            'messages' => $messages,
            'fire' => $fire,
            'oldest_hours' => $oldestHours,
            'rows' => $rows,
            'text' => $this->formatHtml(count($open), $messages, $fire, $oldestHours, $rows),
        ];
    }

    /** Тот же предикат «человек ответил», что снимает дедуп и ack (H5452). */
    private function humanAnsweredAfter(int $chatId, ?\DateTimeInterface $after): bool
    {
        if ($after === null) {
            return false;
        }

        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $chatId)
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $after)
            ->where(fn ($q) => $q
                ->where('responder_type', 'human')
                ->orWhere(fn ($qq) => $qq->whereNull('responder_type')->where('role', 'human')))
            ->exists();
    }

    /**
     * @param  list<array{chat_id: int, label: string, hours: int, messages: int, fire: bool}>  $rows
     */
    private function formatHtml(int $chats, int $messages, int $fire, ?int $oldestHours, array $rows): string
    {
        $lines = ['<b>📮 Подсказки куратору · ждут ответа</b>', ''];

        if ($chats === 0) {
            // Explicit-empty (паттерн H3392): пустая очередь — тоже факт.
            $lines[] = 'Ждут ответа: 0 чатов / 0 сообщений — очередь пуста.';

            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            'Ждут ответа: %d %s / %d %s; старейший — %d ч; 🔥: %d',
            $chats,
            $this->pluralRu($chats, 'чат', 'чата', 'чатов'),
            $messages,
            $this->pluralRu($messages, 'сообщение', 'сообщения', 'сообщений'),
            (int) $oldestHours,
            $fire,
        );

        $lines[] = '';

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '%s %s — %d ч, %d %s',
                $row['fire'] ? '🔥' : '·',
                e($row['label']),
                $row['hours'],
                $row['messages'],
                $this->pluralRu($row['messages'], 'сообщ.', 'сообщ.', 'сообщ.'),
            );
        }

        return implode("\n", $lines);
    }

    private function pluralRu(int $n, string $one, string $few, string $many): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;

        if ($n10 === 1 && $n100 !== 11) {
            return $one;
        }

        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
            return $few;
        }

        return $many;
    }
}
