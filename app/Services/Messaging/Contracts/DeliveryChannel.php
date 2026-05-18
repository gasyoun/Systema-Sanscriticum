<?php

declare(strict_types=1);

namespace App\Services\Messaging\Contracts;

interface DeliveryChannel
{
    public function name(): string;

    /**
     * Deep-link для перехода в бота: юзер кликает -> бот получает /start {token}.
     */
    public function buildDeepLink(string $token): string;

    /**
     * Отправляет документ юзеру. $userIdInChannel — chat_id (TG), user_id (VK/Max).
     * Кидает RuntimeException при ошибке — Job уйдёт в retry/failed.
     */
    public function sendDocument(string $userIdInChannel, string $filePath, string $caption): void;
}
