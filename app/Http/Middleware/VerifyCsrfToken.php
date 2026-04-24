<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Добавляем вебхуки в исключения защиты CSRF
        '/vk-webhook', 
        '/api/vk',
        '/telegram-webhook', // Заодно и Телеграм добавим, чтобы точно не блокировало
        'api/heartbeat',
    ];
}