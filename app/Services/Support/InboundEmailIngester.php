<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\InboundEmail;
use App\Models\User;
use App\Support\UnifiedMessage;
use Illuminate\Support\Facades\DB;

/**
 * H3462: приём одного входящего письма канала email (zabota@samskrte.ru).
 *
 * Контракт (спека docs/INBOUND_EMAIL_CHANNEL_SPEC_2026.md + рулинги MG 24-08):
 *  - дедуп по Message-ID на уровне таблицы inbound_emails (уникальный индекс) —
 *    ретраи проводника/повторный форвард не плодят дублей;
 *  - отправитель резолвится строго по users.email; НЕсовпавший уходит в
 *    видимую очередь (inbound_emails.status='queued', Filament
 *    /admin/inbound-emails) — письмо никогда не выбрасывается молча;
 *  - совпавший: строка chat_messages (role='user', source='email',
 *    is_read=false) + тред SupportConversation через общий
 *    SupportConversationManager — threading строится на существующем ключе,
 *    параллельного механизма нет.
 */
class InboundEmailIngester
{
    /**
     * Принять письмо. Возвращает ['status' => ingested|queued|duplicate, 'inbound' => …].
     *
     * @param  array{message_id: string, from_email: string, from_name?: string|null, subject?: string|null, text: string, received_at?: string|null}  $payload
     * @return array{status: string, inbound: InboundEmail}
     */
    public function ingest(array $payload): array
    {
        $messageId = $this->normalizeMessageId((string) $payload['message_id']);
        $fromEmail = mb_strtolower(trim((string) $payload['from_email']));

        // Дедуп по Message-ID: повторная доставка — это тот же InboundEmail-ряд,
        // вторая строка chat_messages не создаётся.
        $inbound = InboundEmail::firstOrCreate(
            ['message_id' => $messageId],
            [
                'from_email' => $fromEmail,
                'from_name' => $payload['from_name'] ?? null,
                'subject' => $payload['subject'] ?? null,
                'text' => (string) $payload['text'],
                'status' => InboundEmail::STATUS_QUEUED,
                'received_at' => $payload['received_at'] ?? null,
            ],
        );

        if (! $inbound->wasRecentlyCreated) {
            return ['status' => 'duplicate', 'inbound' => $inbound];
        }

        $user = User::query()->where('email', $fromEmail)->first();

        if ($user === null) {
            // Очередь нераспознанных отправителей: видно оператору в Filament.
            return ['status' => 'queued', 'inbound' => $inbound];
        }

        $this->linkToUser($inbound, $user);

        return ['status' => 'ingested', 'inbound' => $inbound->refresh()];
    }

    /** Ручная привязка из очереди (Filament): то же самое, что автосопоставление. */
    public function linkToUser(InboundEmail $inbound, User|int $user): InboundEmail
    {
        $user = $user instanceof User ? $user : User::findOrFail($user);

        if ($inbound->status === InboundEmail::STATUS_INGESTED) {
            return $inbound;
        }

        $message = DB::transaction(function () use ($inbound, $user): ChatMessage {
            $chatMessage = ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'user',
                'text' => $this->composeText($inbound),
                'is_read' => false,
                'source' => UnifiedMessage::CHANNEL_EMAIL,
            ]);

            // Тред поддержки: открыть/переоткрыть тред пользователя и привязать
            // сообщение — тот же conversation key, что у web/VK/TG-бота.
            app(SupportConversationManager::class)->recordMessage($user, $chatMessage);

            $inbound->forceFill([
                'status' => InboundEmail::STATUS_INGESTED,
                'user_id' => $user->id,
                'chat_message_id' => $chatMessage->id,
            ])->save();

            return $chatMessage;
        });

        return $inbound->refresh();
    }

    /** Текст для chat_messages: тема сохраняет контекст, тело — без потерь. */
    private function composeText(InboundEmail $inbound): string
    {
        $subject = trim((string) $inbound->subject);
        $body = trim((string) $inbound->text);

        if ($subject !== '' && ! str_starts_with($body, $subject)) {
            return $subject."\n\n".$body;
        }

        return $body;
    }

    /** RFC 5322 допускает <...> вокруг Message-ID — приводим к голому id. */
    private function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = substr($messageId, 1, -1);
        }

        return trim($messageId);
    }
}
