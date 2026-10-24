<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Payment;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportContact;
use Illuminate\Support\Facades\Log;

/**
 * Контакт лички → плательщик (21-10-2026).
 *
 * Бот дал непривязанному контакту подписанную ссылку на оплату группы
 * (payments.telegram_support_contact_id). После оплаты привязываем контакт к
 * аккаунту плательщика — та же логика, что у ссылки-приглашения H3542
 * (TelegramSupportLinkController): первый писатель выигрывает, чужую привязку
 * не перетираем, private-чат досинхронизируем вручную.
 *
 * Возвращает контакт, только если он теперь привязан именно к плательщику:
 * тогда ссылку-заявку можно слать в эту личку, и одобритель узнает студента.
 */
final class EnrollContactBinder
{
    public function bind(Payment $payment): ?TelegramSupportContact
    {
        if ($payment->telegram_support_contact_id === null || $payment->user_id === null) {
            return null;
        }

        $linkedNow = TelegramSupportContact::query()
            ->whereKey($payment->telegram_support_contact_id)
            ->whereNull('linked_user_id')
            ->update(['linked_user_id' => $payment->user_id]);

        $contact = TelegramSupportContact::query()->with('chat')->find($payment->telegram_support_contact_id);
        if ($contact === null || (int) $contact->linked_user_id !== (int) $payment->user_id) {
            return null;
        }

        if ($linkedNow === 1) {
            if ($contact->chat
                && ($contact->chat->type === null || $contact->chat->type === 'private')
                && $contact->chat->linked_user_id === null
            ) {
                $contact->chat->forceFill(['linked_user_id' => $payment->user_id])->save();
            }

            SupportAiReplyEvent::create([
                'telegram_support_message_id' => null,
                'event_type' => 'dm_contact_linked',
                'meta' => [
                    'via' => 'enroll_pay_link',
                    'contact_id' => $contact->id,
                    'user_id' => $payment->user_id,
                    'payment_id' => $payment->id,
                    'telegram_support_chat_id' => $contact->telegram_support_chat_id,
                ],
            ]);

            Log::info('EnrollContactBinder: telegram-контакт связан с плательщиком', [
                'contact_id' => $contact->id,
                'user_id' => $payment->user_id,
                'payment_id' => $payment->id,
            ]);
        }

        return $contact;
    }
}
