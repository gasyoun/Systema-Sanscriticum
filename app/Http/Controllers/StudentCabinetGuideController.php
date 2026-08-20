<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MarkdownGuide;
use Illuminate\View\View;

/**
 * Иллюстрированный гид студента в кабинете (H3212): GET /dvaram/help.
 */
class StudentCabinetGuideController extends Controller
{
    /** Путь к источнику — КОНСТАНТА, никогда не из запроса. */
    public const SOURCE = 'docs/STUDENT_CABINET_GUIDE_RU.md';

    public function show(): View
    {
        return view('student.guide', [
            'html' => MarkdownGuide::html(self::SOURCE),
        ]);
    }
}
