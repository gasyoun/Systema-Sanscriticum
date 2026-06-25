<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Support\CourseNoteBlockParser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    // Как таблица будет называться в интерфейсе
    protected static ?string $title = 'Обучается на курсах';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('status')
                    ->label('Статус обучения')
                    ->options([
                        'Записался' => 'Записался',
                        'Рассрочка' => 'Рассрочка',
                        'Приостановка' => 'Приостановка',
                        'Льготник' => 'Льготник',
                        'Вернулся' => 'Вернулся',
                        'Выпускник' => 'Выпускник',
                        'Покинул' => 'Покинул',
                        'Исключен' => 'Исключен',
                    ])
                    ->default('Записался')
                    ->required()
                    ->live(),

                Forms\Components\TextInput::make('joined_at_block')
                    ->label('Блок входа')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Номер блока, С которого студент начал учиться (присоединился к потоку в середине). Долги за более ранние блоки не начисляются. Пусто = первый оплаченный блок.'),

                Forms\Components\TextInput::make('left_after_block')
                    ->label('Блок выхода')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Forms\Get $get): bool => in_array(
                        $get('status'),
                        ['Покинул', 'Исключен', 'Выпускник'],
                        true,
                    ))
                    ->helperText('Номер блока этого курса, после которого студент вышел/был отчислен/выпустился. Долги по более поздним блокам не начисляются.'),

                Forms\Components\Textarea::make('note')
                    ->label('Примечание (только по этому курсу)')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $ownerId = $this->getOwnerRecord()->getKey();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Название курса')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Записался', 'Вернулся', 'Выпускник' => 'success',
                        'Рассрочка', 'Приостановка', 'Льготник' => 'warning',
                        'Покинул', 'Исключен' => 'danger',
                        default => 'gray',
                    })
                    // Под бейджем — когда записался: дата первой реальной оплаты
                    // курса. Для записей без оплаты (ручная/льготная) — дата
                    // появления записи в системе. (pivot created_at у legacy =
                    // дата импорта, поэтому опираемся на платёж.)
                    ->description(function ($record) use ($ownerId): ?string {
                        $firstPaid = \App\Models\Payment::query()
                            ->where('user_id', $ownerId)
                            ->where('course_id', $record->id)
                            ->paid()
                            ->where('amount', '>', 0)
                            ->min('created_at');

                        if ($firstPaid) {
                            return 'записан '.\Illuminate\Support\Carbon::parse($firstPaid)->format('d.m.Y');
                        }

                        return $record->pivot?->created_at
                            ? 'в системе с '.$record->pivot->created_at->format('d.m.Y')
                            : null;
                    }),

                Tables\Columns\TextColumn::make('joined_at_block')
                    ->label('Блок входа')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state ? '№'.$state : null)
                    ->getStateUsing(fn ($record) => $record->pivot?->joined_at_block),

                Tables\Columns\TextColumn::make('left_after_block')
                    ->label('Блок выхода')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state ? '№'.$state : null)
                    ->getStateUsing(fn ($record) => $record->pivot?->left_after_block),

                Tables\Columns\TextColumn::make('note')
                    ->label('Примечание')
                    ->wrap() // Позволяет длинному тексту переноситься на новые строки
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Кнопка для ручного добавления студента на курс прямо из его карточки
                Tables\Actions\AttachAction::make()
                    ->label('Записать на курс')
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'Записался' => 'Записался',
                                'Рассрочка' => 'Рассрочка',
                                'Льготник' => 'Льготник',
                            ])
                            ->default('Записался')
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label('Примечание'),
                    ]),
            ])
            ->actions([
                // Распознать блоки входа/выхода из текста примечания и проставить
                // их в пустые колонки. Парсер лишь предлагает — админ сверяет с
                // исходным текстом и подтверждает (формат примечаний произвольный).
                Tables\Actions\Action::make('blocksFromNote')
                    ->label('Блоки из примечания')
                    ->icon('heroicon-m-sparkles')
                    ->iconButton()
                    ->tooltip('Распознать блоки входа/выхода из примечания')
                    ->visible(fn ($record): bool => filled($record->pivot?->note)
                        && (blank($record->pivot?->joined_at_block) || blank($record->pivot?->left_after_block)))
                    ->fillForm(function ($record): array {
                        $parsed = CourseNoteBlockParser::parse($record->pivot?->note);

                        return [
                            // Подставляем распознанное только в пустые колонки.
                            'joined_at_block' => $record->pivot?->joined_at_block ?? $parsed['entry'],
                            'left_after_block' => $record->pivot?->left_after_block ?? $parsed['exit'],
                        ];
                    })
                    ->form(fn ($record): array => [
                        Forms\Components\Placeholder::make('note_preview')
                            ->label('Текст примечания')
                            ->content($record->pivot?->note),
                        Forms\Components\TextInput::make('joined_at_block')
                            ->label('Блок входа')
                            ->numeric()
                            ->minValue(1)
                            ->disabled(filled($record->pivot?->joined_at_block))
                            ->helperText(filled($record->pivot?->joined_at_block) ? 'Уже заполнено — не меняем.' : 'Распознано из примечания, можно поправить.'),
                        Forms\Components\TextInput::make('left_after_block')
                            ->label('Блок выхода')
                            ->numeric()
                            ->minValue(1)
                            ->disabled(filled($record->pivot?->left_after_block))
                            ->helperText(filled($record->pivot?->left_after_block) ? 'Уже заполнено — не меняем.' : 'Распознано из примечания, можно поправить.'),
                    ])
                    ->action(function ($record, array $data): void {
                        $payload = [];

                        // Пишем только в реально пустые колонки (политика «только пустые»).
                        if (blank($record->pivot?->joined_at_block) && filled($data['joined_at_block'] ?? null)) {
                            $payload['joined_at_block'] = (int) $data['joined_at_block'];
                        }
                        if (blank($record->pivot?->left_after_block) && filled($data['left_after_block'] ?? null)) {
                            $payload['left_after_block'] = (int) $data['left_after_block'];
                        }

                        if (empty($payload)) {
                            Notification::make()
                                ->warning()
                                ->title('Нечего проставлять')
                                ->body('Блоки не распознаны или уже заполнены.')
                                ->send();

                            return;
                        }

                        $this->getOwnerRecord()->courses()->updateExistingPivot($record->id, $payload);

                        Notification::make()
                            ->success()
                            ->title('Блоки проставлены')
                            ->body(collect([
                                isset($payload['joined_at_block']) ? 'вход № '.$payload['joined_at_block'] : null,
                                isset($payload['left_after_block']) ? 'выход № '.$payload['left_after_block'] : null,
                            ])->filter()->implode(', '))
                            ->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->label('Изменить статус')
                    ->iconButton()
                    ->tooltip('Редактировать статус и примечание'),
                Tables\Actions\DetachAction::make()
                    ->label('Отвязать')
                    ->iconButton()
                    ->tooltip('Удалить с курса'),
            ])
            ->bulkActions([
                // Массовая простановка блоков из примечаний по выделенным строкам:
                // модалка со сравнительной таблицей (было → распознано → станет),
                // запись только в пустые колонки. Прощёлкивать каждую строку по
                // отдельности не нужно.
                Tables\Actions\BulkAction::make('blocksFromNoteBulk')
                    ->label('Блоки из примечаний')
                    ->icon('heroicon-m-sparkles')
                    ->color('warning')
                    ->modalHeading('Распознанные блоки из примечаний')
                    ->modalWidth('5xl')
                    ->modalSubmitActionLabel('Проставить пустые')
                    ->deselectRecordsAfterCompletion()
                    ->modalContent(fn (\Illuminate\Support\Collection $records) => view(
                        'filament.user-courses.blocks-preview',
                        ['rows' => $this->buildBlockPreview($records)],
                    ))
                    ->action(function (\Illuminate\Support\Collection $records): void {
                        $setEntry = 0;
                        $setExit = 0;
                        $skipped = 0;

                        foreach ($records as $record) {
                            // Парсим заново — состоянию модалки не доверяем.
                            $parsed = CourseNoteBlockParser::parse($record->pivot?->note);
                            $payload = [];

                            if (blank($record->pivot?->joined_at_block) && filled($parsed['entry'])) {
                                $payload['joined_at_block'] = $parsed['entry'];
                            }
                            if (blank($record->pivot?->left_after_block) && filled($parsed['exit'])) {
                                $payload['left_after_block'] = $parsed['exit'];
                            }

                            if (empty($payload)) {
                                $skipped++;

                                continue;
                            }

                            $this->getOwnerRecord()->courses()->updateExistingPivot($record->id, $payload);
                            $setEntry += isset($payload['joined_at_block']) ? 1 : 0;
                            $setExit += isset($payload['left_after_block']) ? 1 : 0;
                        }

                        if ($setEntry === 0 && $setExit === 0) {
                            Notification::make()
                                ->warning()
                                ->title('Нечего проставлять')
                                ->body('По выделенным строкам блоки не распознаны или уже заполнены.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Блоки проставлены')
                            ->body("Вход: {$setEntry}, выход: {$setExit}. Пропущено строк: {$skipped}.")
                            ->send();
                    }),

                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Строки сравнительной таблицы для модалки массовой простановки: текущие
     * значения блоков vs распознанные из примечания vs что реально проставится
     * (только в пустые колонки).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Course>  $records
     * @return list<array<string, mixed>>
     */
    private function buildBlockPreview(\Illuminate\Support\Collection $records): array
    {
        return $records->map(function ($record): array {
            $parsed = CourseNoteBlockParser::parse($record->pivot?->note);
            $currentEntry = $record->pivot?->joined_at_block;
            $currentExit = $record->pivot?->left_after_block;

            return [
                'title' => $record->title,
                'note' => $record->pivot?->note,
                'current_entry' => $currentEntry,
                'current_exit' => $currentExit,
                'parsed_entry' => $parsed['entry'],
                'parsed_exit' => $parsed['exit'],
                'will_set_entry' => blank($currentEntry) && filled($parsed['entry']),
                'will_set_exit' => blank($currentExit) && filled($parsed['exit']),
            ];
        })->all();
    }
}
