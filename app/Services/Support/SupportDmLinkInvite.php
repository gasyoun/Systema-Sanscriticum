<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H3542: мост «незалинкованный партнёр → linked user».
 *
 * Проба H3380 на rusamskrtam дала Автоответов: 0 при 802 хинтах: ack/шаблон/факты
 * требуют привязанного юзера ($user !== null), а аудитория канала в кабинете
 * отсутствует. Когда автоответ заблокирован ТОЛЬКО отсутствием связи, бот один раз
 * за cooldown-окно отправляет приглашение: ссылка-капабилити на страницу
 * /support/link/{token}, где по email (find-or-create, паттерн H324) контакт и
 * чат связываются с кабинетом. После этого обычный конвейер SupportDmAutoReply
 * начинает отвечать сам.
 *
 * Приватность: ни email, ни имя в логах — только id; ответ страницы не различает,
 * был ли аккаунт; 152-FZ поза не меняется. Всё под флагом
 * features.support_dm_link_invite (default OFF) и пер-аккаунтным гейтом
 * auto_reply_enabled, как у автоответов.
 */
final class SupportDmLinkInvite
{
    public const VIA = 'support_dm_link_invite';

    /** Событие отправки приглашения. В недельный отчёт dm_auto_sent НЕ попадает. */
    public const EVENT_SENT = 'dm_link_invite_sent';

    /** Текст с плейсхолдером {url} — выверенный канреплай, не свободная генерация. */
    private const DEFAULT_TEXT = <<<'TEXT'
        Намасте!

        Ваш вопрос дошёл до нас. Бот отвечает мгновенно тем, чей Telegram связан с личным кабинетом школы.

        Свяжите их за минуту: откройте ссылку и укажите вашу почту.
        {url}

        Если аккаунта ещё нет — он создастся автоматически, бесплатно и без пароля. После связывания бот начнёт отвечать сам, а куратор будет видеть ваш курс.
        TEXT;

    public function isEnabled(): bool
    {
        return (bool) config('features.support_dm_link_invite', false);
    }

    /**
     * Порог входа из конвейера автоответов: флаг, пер-аккаунтный гейт,
     * несвязанность контакта и cooldown-окно. Вызывается из SupportDmAutoReply
     * ровно тогда, когда до этого всё остальное упёрлось в $user === null.
     */
    public function offerForIncoming(TelegramSupportMessage $incoming): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! $incoming->telegram_support_contact_id) {
            return false;
        }

        /** @var TelegramSupportContact|null $contact */
        $contact = TelegramSupportContact::query()->find($incoming->telegram_support_contact_id);

        if ($contact === null || $contact->linked_user_id !== null) {
            return false;
        }

        return $this->send($contact, (int) $incoming->telegram_message_id)['sent'];
    }

    /**
     * Отправить приглашение контакту (runtime + backfill). Инварианты:
     * один вызов за cooldown-окно на контакт; токен перегенерируется и гасит
     * прежнюю ссылку; pending исходящее увозит ближайший заход синка.
     *
     * @return array{sent: bool, reason: string}
     */
    public function send(TelegramSupportContact $contact, ?int $replyToMsgId = null): array
    {
        $account = $this->resolveAccount($contact);

        if (! $this->isEnabled()) {
            return ['sent' => false, 'reason' => 'flag_off'];
        }

        if ($account === null || ! (bool) $account->auto_reply_enabled) {
            return ['sent' => false, 'reason' => 'account_gate'];
        }

        if ($contact->chat === null || $contact->chat->type !== 'private') {
            return ['sent' => false, 'reason' => 'not_private'];
        }

        // Cooldown держим атомарно: гонку двух сообщений подряд разруливает
        // повторная проверка внутри блокировки строки.
        $result = DB::transaction(function () use ($contact, $replyToMsgId): array {
            /** @var TelegramSupportContact $fresh */
            $fresh = TelegramSupportContact::query()
                ->whereKey($contact->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->linked_user_id !== null) {
                return ['sent' => false, 'reason' => 'already_linked'];
            }

            if ($this->invitedWithinCooldown($fresh)) {
                return ['sent' => false, 'reason' => 'cooldown'];
            }

            $plaintext = $fresh->issueLinkToken((int) config('services.telegram_support.link_token_ttl_hours', 336));
            $url = route('support.telegram.link', ['token' => $plaintext], true);

            $message = app(SupportReplyService::class)->queueUnlinkedDmMessage(
                $fresh->chat,
                $this->renderText($url),
                $replyToMsgId,
                self::VIA,
            );

            if ($message === null) {
                throw new \RuntimeException('Не удалось поставить pending-исходящее приглашения');
            }

            $fresh->forceFill(['link_invited_at' => now()])->save();

            SupportAiReplyEvent::create([
                'telegram_support_message_id' => $message->id,
                'event_type' => self::EVENT_SENT,
                'meta' => [
                    'via' => self::VIA,
                    'contact_id' => $fresh->id,
                    'chat_id' => (int) $fresh->chat->telegram_chat_id,
                    'outgoing_message_id' => $message->id,
                    'source_telegram_message_id' => $replyToMsgId,
                ],
            ]);

            Log::info('SupportDmLinkInvite: приглашение поставлено в очередь', [
                'contact_id' => $fresh->id,
                'chat_id' => (int) $fresh->chat->telegram_chat_id,
                'outgoing_message_id' => $message->id,
            ]);

            return ['sent' => true, 'reason' => 'sent'];
        });

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function census(int $days): array
    {
        $windowStart = now()->subDays($days);

        $inScope = TelegramSupportContact::query()
            ->whereNull('linked_user_id')
            ->whereHas('chat', fn ($q) => $q
                ->where('type', 'private')
                ->where('last_message_at', '>=', $windowStart))
            ->count();

        $onGatedAccounts = TelegramSupportContact::query()
            ->whereNull('linked_user_id')
            ->whereHas('chat', fn ($q) => $q
                ->where('type', 'private')
                ->where('last_message_at', '>=', $windowStart))
            ->get()
            ->filter(fn (TelegramSupportContact $c) => in_array(
                $this->resolveAccount($c)?->id,
                TelegramSupportAccount::query()->where('auto_reply_enabled', true)->pluck('id')->all(),
            ))
            ->count();

        return [
            'unlinked_recent' => $inScope,
            'unlinked_recent_gated' => $onGatedAccounts,
        ];
    }

    public function invitedWithinCooldown(TelegramSupportContact $contact): bool
    {
        if ($contact->link_invited_at === null) {
            return false;
        }

        $hours = max(1, (int) config('services.telegram_support.link_invite_cooldown_hours', 168));

        return $contact->link_invited_at->gt(now()->subHours($hours));
    }

    private function renderText(string $url): string
    {
        $template = (string) config(
            'services.telegram_support.link_invite_text',
            self::DEFAULT_TEXT,
        );

        return str_replace('{url}', $url, $template);
    }

    private function resolveAccount(TelegramSupportContact $contact): ?TelegramSupportAccount
    {
        $accountId = $contact->chat?->messages()->max('telegram_support_account_id');

        return $accountId !== null
            ? TelegramSupportAccount::query()->find((int) $accountId)
            : null;
    }
}
