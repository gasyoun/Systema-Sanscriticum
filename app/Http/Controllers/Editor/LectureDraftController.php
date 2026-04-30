<?php

declare(strict_types=1);

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Jobs\BuildLectureHtmlJob;
use App\Models\LectureDraft;
use App\Services\Lecture\LecturePatcher;
use App\Services\Lecture\LectureStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контроллер для работы с черновиками лекций из Filament-панели /editor.
 *
 * Все эндпоинты защищены auth + проверкой is_admin || is_lecture_editor.
 * Доступ к чужим черновикам редакторам запрещён (только админ видит всё).
 */
class LectureDraftController extends Controller
{
    public function __construct(private readonly LectureStorage $storage)
    {
    }

    /**
     * Отдаёт HTML собранной лекции (iframe-friendly: same-origin).
     */
    public function preview(LectureDraft $draft): Response|BinaryFileResponse
    {
        $this->authorizeAccess($draft);

        if ($draft->output_html_path === null) {
            abort(404, 'HTML ещё не собран');
        }

        $absolute = Storage::disk('local')->path($draft->output_html_path);
        if (!is_file($absolute)) {
            abort(404, 'Файл не найден на диске: ' . $absolute);
        }

        $html = file_get_contents($absolute);

        // Подменяем относительные пути слайдов и стилей на абсолютные
        $assetBase = url('/editor/lectures/' . $draft->id . '/asset');
        $stylesUrl = url('/lecture-styles/style.css');
        $html = str_replace('src="./src/img/', 'src="' . $assetBase . '/slides/', $html);
        $html = str_replace("src='./src/img/", "src='" . $assetBase . '/slides/', $html);
        $html = preg_replace('#href="\./src/style\.css(\?[^"]*)?"#', 'href="' . $stylesUrl . '"', $html);

        // Конфиг + editor.js перед </head>
        $injection = $this->buildJsInjection($draft);
        $html = preg_replace('/<\/head>/', $injection . '</head>', $html, 1);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Отдаёт ассет лекции (например слайд slides/03_01.jpg) для preview.
     */
    public function asset(LectureDraft $draft, string $path): BinaryFileResponse|StreamedResponse
    {
        $this->authorizeAccess($draft);

        // Жёстко ограничиваем разрешённые подпапки
        if (!preg_match('#^(slides|src)/[A-Za-z0-9_./-]+$#', $path)) {
            abort(403, 'Запрещённый путь');
        }

        $abs = $this->storage->absolutePath($draft, $path);
        if (!is_file($abs)) {
            abort(404);
        }

        return response()->file($abs);
    }

    /**
     * Применяет patch к data.json лекции и (опционально) ребилдит HTML.
     */
    public function patch(Request $request, LectureDraft $draft, LecturePatcher $patcher): JsonResponse
    {
        $this->authorizeAccess($draft);

        $validated = $request->validate([
            'patches' => 'required|array',
            'patches.*.section_id' => 'required|string',
            'patches.*.value' => 'required|string',
            'rebuild' => 'sometimes|boolean',
        ]);

        try {
            $dataJson = $this->storage->dataJsonAbsolute($draft);
            $backupPath = $patcher->applyToFile($dataJson, $validated['patches']);

            $rebuilt = false;
            if ($validated['rebuild'] ?? false) {
                BuildLectureHtmlJob::dispatchSync($draft->id);
                $rebuilt = true;
            }

            return response()->json([
                'ok'       => true,
                'backup'   => basename($backupPath),
                'rebuilt'  => $rebuilt,
                'count'    => count($validated['patches']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function authorizeAccess(LectureDraft $draft): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(401);
        }
        if (!$user->is_admin && !$user->is_lecture_editor) {
            abort(403, 'Нет прав редактора лекций');
        }
        if (!$user->is_admin && $draft->created_by !== $user->id) {
            abort(403, 'Чужой черновик');
        }
    }

    private function buildJsInjection(LectureDraft $draft): string
    {
        $patchUrl = route('editor.lecture.patch', $draft);
        $assetBase = url('/editor/lectures/' . $draft->id . '/asset');
        $token = csrf_token();
        $editorJs = asset('lecture-editor.js');

        return <<<HTML
<script>
window.__LECTURE_BUILDER__ = {
    patchUrl: {$this->jsString($patchUrl)},
    assetBase: {$this->jsString($assetBase)},
    csrfToken: {$this->jsString($token)},
    draftId: {$draft->id}
};
</script>
<script src="{$editorJs}" defer></script>
HTML;
    }

    private function jsString(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
