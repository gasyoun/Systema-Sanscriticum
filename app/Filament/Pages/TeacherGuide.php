<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Руководство преподавателя прямо в панели (H2501, волна 1).
 *
 * Страница читает ЕДИНСТВЕННЫЙ источник — docs/TEACHER_CABINET_GUIDE_RU.md —
 * и рендерит его как Markdown. Ни PDF, ни эта страница не держат собственной
 * копии текста: правка файла в репозитории меняет всё сразу.
 *
 * Граница безопасности, которую нужно назвать вслух: вывод вставляется
 * НЕэкранированным, и это безопасно ровно потому, что путь — константа в коде,
 * а файл закоммичен в репозиторий. Параметра «какой файл показать» у страницы
 * нет и появиться не должно. Понадобится когда-нибудь показывать
 * пользовательский текст — экранирование придётся вернуть.
 */
class TeacherGuide extends Page
{
    /** Путь к источнику — КОНСТАНТА, никогда не из запроса. */
    public const SOURCE = 'docs/TEACHER_CABINET_GUIDE_RU.md';

    /** Куда разворачиваются относительные пути кадров разделов (H2502). */
    public const SCREENSHOT_BASE = 'https://raw.githubusercontent.com/gasyoun/Systema-Sanscriticum/main/docs/screenshots/';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Руководство преподавателя';

    protected static ?string $title = 'Руководство преподавателя';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.teacher-guide';

    /**
     * Доступ объявлен здесь и только здесь: ни один существующий гейт панели
     * этой страницей не затронут. Формула та же, что уже действует в проекте
     * для преподавательских файлов — админ, редактор лекций или пользователь,
     * связанный с карточкой преподавателя.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return (bool) $user->is_admin
            || (bool) $user->is_lecture_editor
            || $user->teacher_id !== null
            || $user->isTeacher();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function sourcePath(): string
    {
        return base_path(self::SOURCE);
    }

    /**
     * Готовый HTML руководства либо null, если файла нет.
     *
     * Файла может не быть на стенде без выкачанных docs/ — это штатная
     * ситуация, страница показывает одну строку, а не падает.
     */
    public function guideHtml(): ?string
    {
        $path = self::sourcePath();

        if (! is_file($path)) {
            return null;
        }

        $markdown = file_get_contents($path);

        if ($markdown === false || trim($markdown) === '') {
            return null;
        }

        return $this->resolveScreenshots(Str::markdown($markdown));
    }

    /**
     * Кадры разделов (H2502) лежат в `docs/screenshots/teacher-guide/` и в
     * Markdown записаны ОТНОСИТЕЛЬНЫМ путём — так они рендерятся на GitHub, где
     * руководство читают чаще всего, и так же их находит сборка PDF (она задаёт
     * базовым каталогом `docs/`).
     *
     * Браузеру этот путь не годится: страница живёт по `/admin/teacher-guide`, и
     * относительный `screenshots/...` он попытался бы взять из `/admin/`.
     * Публиковать копию папки в `public/` — значит завести вторую правду о
     * пятнадцати файлах, поэтому здесь просто переписываем адрес на raw-ссылку
     * публичного репозитория. Нет сети — сломается ровно картинка, текст
     * руководства останется на месте.
     */
    private function resolveScreenshots(string $html): string
    {
        return (string) preg_replace(
            '#(<img[^>]+src=")screenshots/#i',
            '$1'.self::SCREENSHOT_BASE,
            $html
        );
    }
}
