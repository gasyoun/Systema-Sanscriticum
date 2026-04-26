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
        // /api/heartbeat зарегистрирован в routes/web.php → web middleware → CSRF применяется,
        // и для AJAX-вызовов он реально требует исключения.
        // Другие webhook-пути (/api/vk-webhook, /api/telegram/webhook, /api/webhooks/tochka,
        // /api/sync-lessons) живут в routes/api.php и проходят через api middleware,
        // в котором VerifyCsrfToken не используется — добавлять их сюда бессмысленно.
        '/api/heartbeat',
    ];
}