<?php

declare(strict_types=1);

namespace App\Filament\Editor\Widgets;

use App\Filament\Editor\Resources\LectureDraftResource\Pages\EditLectureDraft;
use App\Models\LectureDraft;
use Filament\Widgets\Widget;

/**
 * Баннер фоновой обработки лекции (Phase A — async pipeline).
 *
 * Поллит статус черновика: пока идёт фоновая задача (препроцесс/сборка/ИИ) —
 * показывает «⏳ выполняется…». Как только задача снимает meta.processing —
 * перезагружает страницу, чтобы вернулись кнопки и обновился статус/итог ИИ.
 */
class LectureProcessingWidget extends Widget
{
    protected static string $view = 'filament.editor.widgets.lecture-processing';

    protected int|string|array $columnSpan = 'full';

    /** Передаётся страницей-владельцем (EditRecord прокидывает record в виджеты). */
    public ?LectureDraft $record = null;

    /** Был ли черновик в обработке на прошлом поле — чтобы поймать момент завершения. */
    public bool $wasProcessing = false;

    public function mount(): void
    {
        // Берём advisory-лок сразу при открытии — чтобы другие редакторы увидели
        // «редактирует X» не дожидаясь первого поллинга.
        $this->heartbeat();
    }

    public function refreshStatus(): void
    {
        $this->heartbeat();

        $draft = $this->freshDraft();
        if ($draft === null) {
            return;
        }

        if ($draft->isProcessing()) {
            $this->wasProcessing = true;

            return;
        }

        // Обработка только что завершилась — перезагружаем страницу целиком.
        if ($this->wasProcessing) {
            $this->wasProcessing = false;
            $this->redirect(EditLectureDraft::getUrl(['record' => $draft->getKey()], panel: 'editor'));
        }
    }

    protected function getViewData(): array
    {
        $draft = $this->freshDraft();
        $me = auth()->id();
        $lock = $draft?->activeLock();
        $lockedByOther = $draft !== null && $me !== null && $draft->isLockedByOther((int) $me);

        return [
            'isProcessing' => $draft?->isProcessing() ?? false,
            'label' => $draft?->processingLabel(),
            'startedAt' => $draft?->processingStartedAt(),
            'lastAi' => $draft?->meta['last_ai'] ?? null,
            'lockedByOther' => $lockedByOther,
            'lockName' => $lockedByOther ? ($lock['name'] ?? 'другой редактор') : null,
        ];
    }

    /** Обновляет advisory-лок для текущего пользователя (если не держит другой). */
    private function heartbeat(): void
    {
        $draft = $this->freshDraft();
        $user = auth()->user();
        if ($draft !== null && $user !== null) {
            $draft->heartbeatLock($user->id, (string) $user->name);
        }
    }

    private function freshDraft(): ?LectureDraft
    {
        $id = $this->record?->getKey();

        return $id ? LectureDraft::find($id) : null;
    }
}
