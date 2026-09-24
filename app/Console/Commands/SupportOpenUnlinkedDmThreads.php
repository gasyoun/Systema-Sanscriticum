<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\TechnicalIssueRouter;
use Illuminate\Console\Command;

/**
 * Разовая догрузка в Helpdesk TG-личек без привязки к кабинету, которые висят
 * без ответа (24-09-2026). До флага support_unlinked_dm_threads такие чаты
 * тред не заводили и были видны только в read-only «Аналитике».
 *
 * Кандидат: личка, linked_user_id пуст, последнее сообщение за окно — входящее
 * (после него нет исходящего), входящие без треда. Эти входящие по порядку
 * отдаём в {@see TechnicalIssueRouter::handleIncoming()} — ту же дорогу, что у
 * живого синка, поэтому small talk и прочие правила работают одинаково.
 *
 * По умолчанию сухой прогон. Печатает только chat_id и счётчики — без текстов.
 * Идемпотентна: сообщение с support_conversation_id повторно не берётся.
 */
class SupportOpenUnlinkedDmThreads extends Command
{
    protected $signature = 'support:open-unlinked-dm-threads
        {--days=14 : Окно по последнему сообщению чата, дней}
        {--apply : Реально завести треды (без флага — сухой прогон)}';

    protected $description = 'Завести Helpdesk-треды для висящих TG-личек без привязки к кабинету';

    public function handle(TechnicalIssueRouter $router): int
    {
        if (! config('features.support_unlinked_dm_threads', false)) {
            $this->error('Флаг features.support_unlinked_dm_threads выключен — роутер не заведёт треды. Сначала включите SUPPORT_UNLINKED_DM_THREADS.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $since = now()->subDays(max(1, (int) $this->option('days')));

        $chats = TelegramSupportChat::query()
            ->where('type', 'private')
            ->whereNull('linked_user_id')
            ->where('telegram_chat_id', '!=', TechnicalIssueRouter::TELEGRAM_SERVICE_CHAT_ID)
            ->where('last_message_at', '>=', $since)
            ->orderBy('last_message_at')
            ->get();

        $rows = [];
        $opened = 0;

        foreach ($chats as $chat) {
            $last = $chat->messages()->orderByDesc('sent_at')->orderByDesc('id')->first();
            if (! $last || $last->direction !== 'incoming') {
                continue;
            }

            $lastOutgoingAt = $chat->messages()->where('direction', 'outgoing')->max('sent_at');

            $pending = $chat->messages()
                ->where('direction', 'incoming')
                ->whereNull('support_conversation_id')
                ->when($lastOutgoingAt, fn ($q) => $q->where('sent_at', '>', $lastOutgoingAt))
                ->orderBy('sent_at')
                ->orderBy('id')
                ->get();

            if ($pending->isEmpty()) {
                continue;
            }

            $threadId = null;
            if ($apply) {
                foreach ($pending as $message) {
                    $threadId = $router->handleIncoming($message, $this->payloadFor($message, $chat), null, 'private')?->id ?? $threadId;
                }
                if ($threadId !== null) {
                    $opened++;
                }
            }

            $rows[] = [
                $chat->telegram_chat_id,
                $pending->count(),
                (int) $last->sent_at?->diffInHours(now()),
                $apply ? ($threadId !== null ? "тред #{$threadId}" : 'пропущен (small talk)') : '—',
            ];
        }

        $this->table(['chat_id', 'входящих без ответа', 'ждёт, ч', 'итог'], $rows);
        $this->info(sprintf(
            '%s: чатов-кандидатов %d%s.',
            $apply ? 'Применено' : 'Сухой прогон',
            count($rows),
            $apply ? ", тредов заведено/дополнено {$opened}" : ' (запустите с --apply)',
        ));

        return self::SUCCESS;
    }

    /**
     * Payload как у живого синка ({@see \App\Services\TelegramSupport\TelegramSupportSyncService::rerouteUnlinkedIncoming()}),
     * плюс имя автора из контакта — чтобы в Helpdesk был человек, а не «Аноним».
     *
     * @return array<string, mixed>
     */
    private function payloadFor(TelegramSupportMessage $message, TelegramSupportChat $chat): array
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $payload['direction'] = 'incoming';
        $payload['text'] = $message->text;
        $payload['telegram_chat_id'] = $message->telegram_chat_id;
        $payload['telegram_message_id'] = $message->telegram_message_id;
        $payload['telegram_user_id'] ??= $message->contact?->telegram_user_id;
        $payload['contact_name'] ??= $message->contact?->name;
        $payload['contact_username'] ??= $message->contact?->username ?? $chat->username;

        return $payload;
    }
}
