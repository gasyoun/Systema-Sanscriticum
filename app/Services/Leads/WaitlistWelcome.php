<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\LandingBot;
use App\Models\Lead;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\WaitlistNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Приветствие подписчика статусов курса (H3339): после первой привязки чата
 * к заявке бот присылает полный словарь статусов и обещание тишины.
 *
 * Вызывается из webhook-jobs сразу после записи chat_id в Lead. Магнитный
 * сценарий не трогаем: там юзер получает файл с подписью, а не словарь —
 * приветствие уходит только лендингам с status_block без файла-магнита.
 */
final class WaitlistWelcome
{
    /**
     * Слать только при ПЕРВОЙ привязке канала (дедуп перепривязки): повторный
     * /start с тем же токеном не спамит словарём второй раз.
     */
    public static function sendIfFreshlyBound(Lead $lead, string $channelName, string $userIdInChannel, DeliveryChannelManager $channels): void
    {
        $landing = $lead->landingPage;

        if (! $landing || ! $landing->hasStatusBlock() || $landing->hasLeadMagnet()) {
            return;
        }

        $channel = $channels->get($channelName);

        // «Свой бот на лендинг»: здоровается тот бот, к которому юзер реально пришёл.
        if ($channelName === 'telegram' && $channel instanceof TelegramDeliveryChannel) {
            $bot = $landing->bot;
            if ($bot && $bot instanceof LandingBot && $bot->isUsable()) {
                $channel = $channel->usingCredentials($bot->tg_bot_token, $bot->tg_bot_username);
            }
        }

        try {
            $channel->sendMessage($userIdInChannel, WaitlistNotifier::welcomeText());
        } catch (\Throwable $e) {
            // Привязка уже записана; молчаливый пропуск приветствия хуже ретрая,
            // но валить весь вебхук из-за косметики не будем.
            Log::warning('WaitlistWelcome: не удалось отправить приветствие', [
                'lead_id' => $lead->id,
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
