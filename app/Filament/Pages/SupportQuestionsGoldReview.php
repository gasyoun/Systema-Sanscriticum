<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\SupportQuestionReviewItem;
use App\Models\SupportQuestionReviewSample;
use App\Models\User;
use App\Services\SupportQuestions\GoldReviewException;
use App\Services\SupportQuestions\GoldReviewService;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use App\Services\SupportQuestions\StaleGoldSampleException;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * H5773 — защищённый экран gold-ревью 100 сообщений (residual H5709).
 *
 * Слепая разметка: текст сообщения без предсказания модели; предсказание
 * конкретной строки раскрывается ТОЛЬКО после записи её gold-метки. Тексты
 * читаются живой связью и не покидают доверенное окружение — наружу уходят
 * только счётчики и вердикт импортёра (гейт — у исправленного H5768).
 *
 * Доступ: admin/manager (+super_admin) — гость и обычный студент до панели
 * не доходят, преподаватель/бухгалтер закрыт canAccess.
 */
class SupportQuestionsGoldReview extends Page
{
    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'Gold-ревью вопросов';

    protected static ?int $navigationSort = 13;

    protected static ?string $title = 'Gold-ревью вопросов';

    protected static ?string $slug = 'support-questions-gold-review';

    protected static string $view = 'filament.pages.support-questions-gold-review';

    public ?int $sampleId = null;

    public int $at = 1;

    // Форма заморозки нового сэмпла (окно всего бэкфилла — как H5709).
    public ?string $freezeFrom = null;

    public ?string $freezeTo = null;

    public int $freezeSize = 100;

    /** Агрегаты последнего экспорта: только счётчики/вердикт, без текстов. */
    public ?string $lastVerdict = null;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public function mount(?int $sample = null): void
    {
        $this->sampleId = $sample
            ?? SupportQuestionReviewSample::query()
                ->where('status', SupportQuestionReviewSample::STATUS_OPEN)
                ->latest('id')
                ->value('id');

        $this->at = $this->resumePosition();
    }

    public function getServiceProperty(): GoldReviewService
    {
        return app(GoldReviewService::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSamplesProperty(): array
    {
        return SupportQuestionReviewSample::query()
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (SupportQuestionReviewSample $s): array => [
                'id' => $s->id,
                'window' => $s->windowLabel(),
                'version' => $s->classifier_version,
                'status' => $s->status,
                // H5781: информационный чип только на завершённых сэмплах;
                // идущее ревью под старой версией остаётся размечаемым.
                'stale' => $s->classifier_version !== QuestionMessageClassifier::VERSION
                    && $s->status === SupportQuestionReviewSample::STATUS_COMPLETED,
                'progress' => $this->service->progress($s),
            ])
            ->all();
    }

    public function getActiveSampleProperty(): ?SupportQuestionReviewSample
    {
        return SupportQuestionReviewSample::query()->find($this->sampleId);
    }

    public function getCurrentItemProperty(): ?SupportQuestionReviewItem
    {
        // Слепая разметка на уровне сериализации: predicted_primary скрыт из
        // любой сериализации модели целиком (SupportQuestionReviewItem
        // ::$hidden), рендер читает атрибут напрямую только когда метка есть.
        return $this->activeSample?->items()
            ->where('position', $this->at)
            ->with(['label', 'classification.message'])
            ->first();
    }

    public function getProgressProperty(): array
    {
        return $this->activeSample
            ? $this->service->progress($this->activeSample)
            : ['total' => 0, 'labeled' => 0, 'remaining' => 0, 'complete' => false, 'first_unlabeled' => null];
    }

    public function selectSample(int $id): void
    {
        $this->sampleId = $id;
        $this->at = $this->resumePosition();
    }

    public function goTo(int $position): void
    {
        $total = $this->progress['total'];
        $this->at = max(1, min($position, max(1, $total)));
    }

    public function nextUnlabeled(): void
    {
        $first = $this->progress['first_unlabeled'];
        $this->at = $first ?? min($this->at + 1, max(1, $this->progress['total']));
    }

    public function saveLabel(?string $gold = null): void
    {
        $sample = $this->activeSample;
        $item = $this->currentItem;
        $reviewer = auth()->user();

        if ($sample === null || $item === null || ! $reviewer instanceof User || $gold === null) {
            return;
        }

        try {
            $this->service->setGoldLabel($sample, $item->id, $reviewer, $gold);
        } catch (StaleGoldSampleException $e) {
            Notification::make()->title('Выборка устарела')->body($e->getMessage())->danger()->send();

            return;
        } catch (GoldReviewException $e) {
            Notification::make()->title('Метка не записана')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Метка записана')
            ->body('Прогресс: '.($this->progress['labeled']).'/'.$this->progress['total'])
            ->success()
            ->send();

        // Курсор — на первую неразмеченную (resume по смыслу).
        $first = $this->progress['first_unlabeled'];
        if ($first !== null) {
            $this->at = $first;
        }
    }

    public function runFreeze(): void
    {
        $this->validate([
            'freezeFrom' => ['nullable', 'date'],
            'freezeTo' => ['nullable', 'date', 'required_with:freezeFrom'],
            'freezeSize' => ['integer', 'min:1', 'max:500'],
        ]);

        $agg = $this->service->freezeFromCommand(
            ['from' => $this->freezeFrom, 'to' => $this->freezeTo],
            $this->freezeSize,
            auth()->id(),
        );

        if (($agg['frozen'] ?? false) === true) {
            $this->sampleId = (int) $agg['sample_id'];
            $this->at = $this->resumePosition();
            Notification::make()
                ->title('Выборка заморожена')
                ->body($agg['sample'].' сообщений, окно '.$agg['window'])
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Заморозка не удалась')
                ->body($agg['note'] ?? $agg['error'] ?? 'подходящих сообщений меньше выборки')
                ->warning()
                ->send();
        }
    }

    public function exportAndVerify(): void
    {
        $sample = $this->activeSample;
        if ($sample === null) {
            return;
        }

        try {
            $result = $this->service->verdictViaImporter($sample);
        } catch (GoldReviewException $e) {
            Notification::make()->title('Экспорт не выполнен')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->lastVerdict = json_encode(
            $result['verdict'],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        Notification::make()
            ->title('Gold-лист записан, вердикт импортёра получен')
            ->body('Лист: '.basename((string) $result['sheet']))
            ->success()
            ->send();
    }

    private function resumePosition(): int
    {
        $sample = $this->activeSample;
        if ($sample === null) {
            return 1;
        }
        $first = $this->service->progress($sample)['first_unlabeled'];

        return $first ?? 1;
    }
}
