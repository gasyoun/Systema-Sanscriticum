<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContentCalendarSlotResource\Pages;
use App\Models\ContentCalendarSlot;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Monthly content calendar ops surface (H1564). No live VK publish here.
 */
class ContentCalendarSlotResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ContentCalendarSlot::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 138;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Календарь контента';

    protected static ?string $pluralModelLabel = 'Слоты календаря';

    protected static ?string $modelLabel = 'Слот';

    public static function canViewAny(): bool
    {
        return config('features.content_calendar') === true && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.content_calendar') === true && RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('slot_date')->label('Дата')->disabled(),
            Forms\Components\TextInput::make('slot_type')->label('Тип')->disabled(),
            Forms\Components\TextInput::make('status')->label('Статус')->disabled(),
            Forms\Components\DateTimePicker::make('publish_at')->label('Публикация (MSK)'),
            Forms\Components\TextInput::make('title')->label('Заголовок')->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('body')->label('Текст')->rows(8)->columnSpanFull(),
            Forms\Components\TextInput::make('source_ref')->label('Источник (id)')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slot_date')
            ->columns([
                Tables\Columns\TextColumn::make('slot_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('slot_type')->label('Тип')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('title')->label('Заголовок')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('body')->label('Текст')->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('publish_at')->label('Publish at')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    ContentCalendarSlot::STATUS_EMPTY => 'empty',
                    ContentCalendarSlot::STATUS_DRAFT => 'draft',
                    ContentCalendarSlot::STATUS_KEPT => 'kept',
                    ContentCalendarSlot::STATUS_SCHEDULED => 'scheduled',
                    ContentCalendarSlot::STATUS_PUBLISHED => 'published',
                    ContentCalendarSlot::STATUS_CANCELED => 'canceled',
                ]),
                Tables\Filters\SelectFilter::make('slot_type')->label('Тип')->options([
                    ContentCalendarSlot::TYPE_EVERGREEN => 'evergreen',
                    ContentCalendarSlot::TYPE_CLIP_TEASE => 'clip_tease',
                    ContentCalendarSlot::TYPE_FORWARD => 'forward',
                    ContentCalendarSlot::TYPE_EVENT => 'event',
                    ContentCalendarSlot::TYPE_EMPTY => 'empty',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('keep')
                    ->label('Keep')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ContentCalendarSlot $record): void {
                        $record->markKept();
                        Notification::make()->title('Слот запланирован')->success()->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ContentCalendarSlot $record): bool => $record->canCancel())
                    ->action(function (ContentCalendarSlot $record): void {
                        if (! $record->canCancel()) {
                            Notification::make()->title('Отмена недоступна (менее 24 ч до публикации)')->warning()->send();

                            return;
                        }
                        $record->markCanceled();
                        Notification::make()->title('Слот отменён')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('keep')
                    ->label('Keep')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            /** @var ContentCalendarSlot $record */
                            $record->markKept();
                        }
                        Notification::make()->title('Выбранные слоты запланированы')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $n = 0;
                        foreach ($records as $record) {
                            /** @var ContentCalendarSlot $record */
                            if ($record->canCancel()) {
                                $record->markCanceled();
                                $n++;
                            }
                        }
                        Notification::make()->title("Отменено: {$n}")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCalendarSlots::route('/'),
            'edit' => Pages\EditContentCalendarSlot::route('/{record}/edit'),
        ];
    }
}
