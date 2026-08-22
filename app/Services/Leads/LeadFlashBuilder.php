<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Services\Messaging\DeliveryChannelManager;

/**
 * Собирает flash-данные для редиректа на thank-you после успешного лида.
 * Раньше эта логика жила прямо в LeadController::store и раздувала метод
 * до 150 строк. Вынесли — контроллер стал тонким.
 */
final class LeadFlashBuilder
{
    public function __construct(
        private readonly DeliveryChannelManager $channels,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Валидированный input — нужен только source_article_id.
     * @return array<string, mixed>
     */
    public function build(Lead $lead, ?LandingPage $landing, array $validated): array
    {
        $flash = [
            'success' => 'Ваша заявка успешно отправлена! Менеджер свяжется с вами.',
        ];

        if ($landing) {
            $this->applyLandingPixels($flash, $landing);
        }

        // Lead-magnet flash имеет приоритет над redirect_after_submit_url:
        // без этого юзер уйдёт на лендинг и не получит файл.
        // H3339: magnet_token теперь бывает и у «подписочных» лидов (без файла),
        // поэтому файловая ветка гейтится настоящим магнитом на лендинге.
        if ($lead->magnet_token && $lead->magnet_channel
            && ($landing === null || $landing->hasLeadMagnet())) {
            $this->applyMagnetDeepLink($flash, $lead, $landing);
        }

        if ($landing && $landing->hasStatusBlock() && $lead->magnet_token) {
            $flash['status_connect_links'] = $this->statusConnectLinks($lead, $landing);
        }

        if (! $landing && ! empty($validated['source_article_id'])) {
            $this->applyBlogPixels($flash);
        }

        return $flash;
    }

    private function applyLandingPixels(array &$flash, LandingPage $landing): void
    {
        if (! empty($landing->yandex_metrika_id)) {
            $flash['yandex_id'] = $landing->yandex_metrika_id;
        }

        if (! empty($landing->vk_pixel_id)) {
            $flash['vk_id'] = $landing->vk_pixel_id;
        }

        $flash['conversion_event'] = 'lead';

        if (! empty($landing->redirect_after_submit_url)) {
            $flash['redirect_url'] = $landing->redirect_after_submit_url;
        }
    }

    /**
     * Кнопки «Подключить уведомления» (H3339): deep-link каждого настроенного
     * канала на один и тот же binding-токен лида. Без авто-редиректа — юзер
     * остаётся на странице и выбирает мессенджер сам.
     *
     * @return array<string, string> channel => url
     */
    public function statusConnectLinks(Lead $lead, ?LandingPage $landing): array
    {
        if (! $lead->magnet_token) {
            return [];
        }

        $marketing = MarketingSetting::cached();

        // Только полностью настроенные каналы — иначе кнопка ведёт в «мёртвого» бота.
        $links = [];
        foreach ($marketing?->configuredChannels() ?? [] as $channelName) {
            $links[$channelName] = $this->channels->get($channelName)->buildDeepLink($lead->magnet_token);
        }

        // «Свой бот на лендинг»: telegram перекрывается ботом лендинга, если он есть.
        $bot = $landing?->bot;
        if ($bot && $bot->isUsable()) {
            $links['telegram'] = $bot->deepLink($lead->magnet_token);
        }

        return $links;
    }

    private function applyMagnetDeepLink(array &$flash, Lead $lead, ?LandingPage $landing): void
    {
        $marketing = MarketingSetting::cached();

        // Кнопки показываем только для тех каналов, у которых заполнен и
        // username, и token — иначе юзер уйдёт в бот, который не сможет
        // ответить (token нужен для sendDocument).
        $deepLinks = [];
        foreach ($marketing?->configuredChannels() ?? [] as $channelName) {
            $deepLinks[$channelName] = $this->channels->get($channelName)->buildDeepLink($lead->magnet_token);
        }

        // «Свой бот на лендинг»: telegram deep-link строим по username бота
        // лендинга (перекрывая глобальный). Работает и когда глобальный telegram
        // вообще не настроен — главное, что у лендинга есть свой бот.
        $bot = $landing?->bot;
        if ($bot && $bot->isUsable()) {
            $deepLinks['telegram'] = $bot->deepLink($lead->magnet_token);
        }

        if (empty($deepLinks)) {
            return;
        }

        // Показываем ТОЛЬКО канал, который студент указал в форме (magnet_channel) — чтобы он
        // не ушёл в чужую кнопку и не потерял токен. VK отдаёт ref лишь в первом сообщении
        // нового диалога (см. ProcessVkMagnetCallback), поэтому telegram-лид, нажавший VK,
        // остаётся без выдачи. Если его канал недоставляем (например, указал VK, а VK-бот
        // не настроен) — деградируем на все каналы, чтобы дать хоть один рабочий путь.
        if (isset($deepLinks[$lead->magnet_channel])) {
            $deepLinks = [$lead->magnet_channel => $deepLinks[$lead->magnet_channel]];
        }

        $flash['magnet_deep_links'] = $deepLinks;
        $flash['magnet_title'] = $landing?->lead_magnet_title;
        $flash['magnet_channel'] = $lead->magnet_channel;

        // В redirect-режиме сохраняем авто-редирект в канал лида.
        // В page-режиме redirect_url не ставим — юзер выбирает кнопкой.
        $deliveryMode = $marketing?->magnet_delivery_mode ?? 'redirect';
        if ($deliveryMode === 'redirect' && isset($deepLinks[$lead->magnet_channel])) {
            $flash['redirect_url'] = $deepLinks[$lead->magnet_channel];
        }
    }

    private function applyBlogPixels(array &$flash): void
    {
        $marketing = MarketingSetting::cached();

        if (! $marketing) {
            return;
        }

        if (! empty($marketing->blog_yandex_metrika_id)) {
            $flash['yandex_id'] = $marketing->blog_yandex_metrika_id;
        }

        if (! empty($marketing->blog_vk_pixel_id)) {
            $flash['vk_id'] = $marketing->blog_vk_pixel_id;
        }

        $flash['conversion_event'] = 'lead_from_article';
    }
}
