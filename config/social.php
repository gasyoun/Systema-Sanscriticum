<?php

declare(strict_types=1);

/**
 * Конфиг социальных ссылок для шапки кабинета.
 * Значения тянем из .env — правятся быстро, без миграций и деплоя кода.
 * Если значение null/пусто — кнопка в шапке не рендерится.
 */
return [
    'vk'       => env('SOCIAL_VK_URL'),
    'telegram' => env('SOCIAL_TELEGRAM_URL'),
    'facebook' => env('SOCIAL_FACEBOOK_URL'),
    'website'  => env('SOCIAL_WEBSITE_URL'),
];