<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\MarkdownGuide;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Публичный гид личного кабинета (H3499): GET /help/kabinet.
 *
 * Тот же закоммиченный источник, что и кабинетный /dvaram/help, но БЕЗ auth:
 * адресат — тот, кто ещё не вошёл (рассылки, анонсы в Telegram, куратор).
 */
class PublicCabinetGuideController extends Controller
{
    /**
     * FAQ-строка гида — единственный источник бот-пути для шапки (H6315):
     * правится в docs/STUDENT_CABINET_GUIDE_RU.md, не в blade.
     */
    private const NO_CABINET_FAQ_ANCHOR = '**Кабинета нет, хочу без сайта и без куратора.**';

    public function show(): View
    {
        return view('help.cabinet-guide', [
            'html' => MarkdownGuide::html(StudentCabinetGuideController::SOURCE),
            'botPathLine' => $this->botPathLine(),
        ]);
    }

    /**
     * HTML-фрагмент «Кабинета нет, хочу без сайта и без куратора» из гида;
     * пустая строка, если якорь пропал (CabinetDocsFreshnessTest краснеет).
     */
    public function botPathLine(): string
    {
        $source = base_path(StudentCabinetGuideController::SOURCE);

        if (! is_file($source)) {
            return '';
        }

        $raw = (string) file_get_contents($source);
        $start = mb_strpos($raw, self::NO_CABINET_FAQ_ANCHOR);

        if ($start === false) {
            return '';
        }

        $rest = mb_substr($raw, $start);
        $end = mb_strpos($rest, "\n\n");
        $block = $end === false ? $rest : mb_substr($rest, 0, $end);

        return trim((string) Str::markdown(trim($block)));
    }
}
