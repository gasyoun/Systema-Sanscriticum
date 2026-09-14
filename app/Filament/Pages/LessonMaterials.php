<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\LessonMaterials\LessonMaterialsStatsWidget;
use App\Filament\Resources\LessonResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LessonMaterials extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Материалы уроков';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Материалы уроков';

    protected static ?string $slug = 'lesson-materials';

    protected static string $view = 'filament.pages.lesson-materials';

    public static function canAccess(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    protected function getHeaderWidgets(): array
    {
        return [LessonMaterialsStatsWidget::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Lesson::query()->with('course'))
            ->groups([
                Group::make('course.title')->label('Курс')->collapsible(),
            ])
            ->defaultGroup('course.title')
            ->defaultSort('lesson_date')
            ->columns([
                TextColumn::make('title')->label('Урок')->searchable()->wrap()->limit(70)
                    ->url(fn (Lesson $record): string => LessonResource::getUrl('edit', ['record' => $record]))
                    ->color('primary'),

                TextColumn::make('block_number')->label('Блок')->alignCenter()->toggleable(),

                TextColumn::make('lesson_date')->label('Дата')->date('d.m.Y')->sortable()->toggleable(),

                IconColumn::make('has_core_materials')->label('Материалы')->boolean()->alignCenter()
                    ->getStateUsing(fn (Lesson $record): bool => $record->hasCoreMaterials()),

                IconColumn::make('has_video')->label('Видео')->boolean()->alignCenter()
                    ->getStateUsing(fn (Lesson $record): bool => $record->hasVideo()),

                TextColumn::make('attachments_count')->label('Вложения')->alignCenter()->badge()
                    ->getStateUsing(fn (Lesson $record): string => $record->attachmentsCount() ? (string) $record->attachmentsCount() : '—')
                    ->color(fn (string $state): string => $state === '—' ? 'gray' : 'success')
                    ->tooltip(fn (Lesson $record): ?string => self::attachmentsTooltip($record)),

                TextColumn::make('attachment_names')->label('Файлы')->wrap()->limit(80)
                    ->getStateUsing(fn (Lesson $record): string => implode(', ', $record->attachmentBasenames()) ?: '—')
                    ->tooltip(fn (Lesson $record): ?string => ($names = $record->attachmentBasenames()) === [] ? null : implode("\n", $names)),

                IconColumn::make('has_transcript')->label('Транскрипт')->boolean()->alignCenter()
                    ->getStateUsing(fn (Lesson $record): bool => $record->hasTranscript()),

                IconColumn::make('has_homework')->label('ДЗ')->boolean()->alignCenter()->toggleable()
                    ->getStateUsing(fn (Lesson $record): bool => $record->hasHomeworkMaterial()),

                IconColumn::make('has_flashcards')->label('Флешкарты')->boolean()->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->getStateUsing(fn (Lesson $record): bool => $record->hasFlashcards()),
            ])
            ->filters([
                SelectFilter::make('course_id')->label('Курс')
                    ->options(fn (): array => Course::orderBy('title')->pluck('title', 'id')->all()),

                SelectFilter::make('material_type')->label('Тип материала')
                    ->options([
                        'core' => 'Есть материалы',
                        'none' => 'Без материалов',
                        'video' => 'Видео',
                        'attachments' => 'Вложения',
                        'transcript' => 'Транскрипт',
                        'homework' => 'Домашка',
                        'flashcards' => 'Флешкарты',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'core' => $query->withCoreMaterials(),
                        'none' => $query->withoutCoreMaterials(),
                        'video' => $query->withVideo(),
                        'attachments' => $query->withAttachments(),
                        'transcript' => $query->withTranscript(),
                        'homework' => $query->withHomework(),
                        'flashcards' => $query->withFlashcards(),
                        default => $query,
                    }),

                TernaryFilter::make('is_published')->label('Публикация')
                    ->placeholder('Все')->trueLabel('Опубликованные')->falseLabel('Черновики'),
            ])
            ->recordUrl(fn (Lesson $record): string => LessonResource::getUrl('edit', ['record' => $record]))
            ->actions([
                Action::make('openLesson')
                    ->label('Открыть урок')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Lesson $record): string => LessonResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultPaginationPageOption(50)
            ->paginated([25, 50, 100, 'all']);
    }

    private static function attachmentsTooltip(Lesson $lesson): ?string
    {
        $types = $lesson->attachmentTypes();
        if (empty($types)) {
            return null;
        }

        $labels = ['pdf' => 'PDF', 'audio' => 'Аудио', 'video' => 'Видео', 'archive' => 'Архив', 'doc' => 'Документы', 'other' => 'Прочее'];

        return collect($types)
            ->map(fn (int $count, string $type): string => ($labels[$type] ?? $type).': '.$count)
            ->implode(', ');
    }
}
