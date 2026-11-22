<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MarkdownGuide;
use Illuminate\View\View;

/**
 * Публичный гид преподавателя (рулинг MG 21-11-2026: любые инструкции,
 * связанные с учебным процессом, — на сайте, не на GitHub): GET /help/uchitelyu.
 *
 * Источник — закоммиченный docs/TEACHER_HELP_RU.md (тот же deploys-цикл, что и
 * код бота: страница не может разойтись с поведением). Адресат — преподаватель
 * и куратор; страница публичная, секретов не содержит.
 */
class PublicTeacherHelpController extends Controller
{
    /** Путь к источнику — КОНСТАНТА, никогда не из запроса. */
    public const SOURCE = 'docs/TEACHER_HELP_RU.md';

    public function show(): View
    {
        return view('help.teacher', [
            'html' => MarkdownGuide::html(self::SOURCE),
        ]);
    }
}
