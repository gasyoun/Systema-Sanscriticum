<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MarkdownGuide;
use Illuminate\View\View;

/**
 * Публичный гид личного кабинета (H3499): GET /help/kabinet.
 *
 * Тот же закоммиченный источник, что и кабинетный /dvaram/help, но БЕЗ auth:
 * адресат — тот, кто ещё не вошёл (рассылки, анонсы в Telegram, куратор).
 */
class PublicCabinetGuideController extends Controller
{
    public function show(): View
    {
        return view('help.cabinet-guide', [
            'html' => MarkdownGuide::html(StudentCabinetGuideController::SOURCE),
        ]);
    }
}
