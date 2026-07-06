<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Services\UnitEconomicsService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «Юнит-экономика»: по выбранному курсу и блоку — цена урока для ученика, доход
 * преподавателя за урок (факт vs теория) и прибыль ИП за урок. Справочный экран
 * поверх UnitEconomicsService; ставки — из config/economics.php.
 */
class UnitEconomics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'Юнит-экономика';

    protected static ?string $title = 'Юнит-экономика урока';

    protected static ?string $slug = 'unit-economics';

    protected static string $view = 'filament.pages.unit-economics';

    /** Выбранный курс (wire:model.live). */
    public ?int $courseId = null;

    /** Выбранный номер блока (wire:model.live). */
    public ?int $blockNumber = null;

    /** Ручная правка CAC на ученика (₽); null = авто из рекламы. */
    public ?float $cacOverride = null;

    public static function canAccess(): bool
    {
        return RoleGate::accounting();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::accounting();
    }

    public function mount(): void
    {
        $this->courseId = Course::orderBy('title')->value('id');
        $this->syncDefaultBlock();
    }

    public function updatedCourseId(): void
    {
        $this->blockNumber = null;
        $this->syncDefaultBlock();
    }

    private function syncDefaultBlock(): void
    {
        if ($this->courseId && $this->blockNumber === null) {
            $this->blockNumber = CourseBlock::where('course_id', $this->courseId)
                ->orderBy('number')->value('number');
        }
    }

    /** @return array<int, string> */
    public function getCourseOptionsProperty(): array
    {
        return Course::orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, string> */
    public function getBlockOptionsProperty(): array
    {
        if (! $this->courseId) {
            return [];
        }

        return CourseBlock::where('course_id', $this->courseId)
            ->orderBy('number')
            ->get()
            ->mapWithKeys(fn (CourseBlock $b) => [
                (int) $b->number => 'Блок '.$b->number.($b->title ? ' — '.$b->title : ''),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $data = ['found' => false];

        if ($this->courseId && $this->blockNumber !== null) {
            $override = ($this->cacOverride !== null && $this->cacOverride !== '')
                ? (float) $this->cacOverride
                : null;

            $data = app(UnitEconomicsService::class)
                ->forBlock((int) $this->courseId, (int) $this->blockNumber, $override);
        }

        return [
            'courseOptions' => $this->course_options,
            'blockOptions' => $this->block_options,
            'econ' => $data,
        ];
    }
}
