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
     * $displayName — имя файла, под которым юзер увидит его в мессенджере;
     * если null — берётся basename($filePath) (обычно это ULID от Curator, юзеру не очень).
     * Кидает RuntimeException при ошибке — Job уйдёт в retry/failed.
     */
    public function sendDocument(string $userIdInChannel, string $filePath, string $caption, ?string $displayName = null): void;

    /**
     * Отправляет простое текстовое сообщение юзеру (без вложений).
     * $userIdInChannel — chat_id (TG), user_id (VK/Max). $text — готовый текст
     * (TG поддерживает HTML-разметку). Кидает RuntimeException при ошибке.
     */
    public function sendMessage(string $userIdInChannel, string $text): void;
}
