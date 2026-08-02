<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита вебхука баллов экзамена «Санка» (n8n ← Google-таблица преподавателя):
 * секрет в заголовке X-Webhook-Secret сверяется с services.n8n.exam_scores_secret.
 * Пусто → эндпоинт выключен (403).
 */
final class VerifyExamScoresWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.n8n.exam_scores_secret', '');
        $received = (string) $request->header('X-Webhook-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid exam-scores webhook secret');
        }

        return $next($request);
    }
}
