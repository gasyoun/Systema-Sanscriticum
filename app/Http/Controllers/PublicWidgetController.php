<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\PublicScheduleController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Голый, встраиваемый в iframe виджет расписания (H1427, wave 1b).
 *
 * Отдаёт standalone HTML-документ (без layout сайта, без @vite, без auth). Данные
 * подтягивает клиентский JS с {@see PublicScheduleController}
 * (`/api/public/schedule`). Заголовок `Content-Security-Policy: frame-ancestors`
 * выставляется ТОЛЬКО на этом ответе — глобального CSP/X-Frame-Options в проекте
 * нет, поэтому это по построению локальная область: ничего site-wide не ослабляется.
 */
class PublicWidgetController extends Controller
{
    /**
     * Origin'ы, которым разрешено встраивать виджет в iframe.
     */
    private const FRAME_ANCESTORS = "frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru";

    public function schedule(Request $request): Response
    {
        return response()
            ->view('widgets.schedule', [
                'feedUrl' => route('api.public.schedule'),
            ])
            ->header('Content-Security-Policy', self::FRAME_ANCESTORS);
    }
}
