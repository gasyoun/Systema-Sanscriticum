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
    'phone'       => env('SOCIAL_PHONE'),         // Например: +7 999 123-45-67
    'phone_clean' => env('SOCIAL_PHONE_CLEAN'),   // Например: +79991234567 (для tel:)
    'email'       => env('SOCIAL_EMAIL'),         // Например: info@samskrte.ru
];