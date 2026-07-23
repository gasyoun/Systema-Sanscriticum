<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use App\Models\Course;
use App\Services\Email\CampaignSender;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;

/**
 * H1449 B8 — homegrown campaign engine admin, modeled on AnnouncementResource.
 * Entirely gated on the `email_campaigns` flag (in addition to AdminOnly's
 * role gate) — with the flag OFF this resource must not appear at all.
 */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 121;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Email-кампании';

    protected static ?string $pluralModelLabel = 'Email-кампании';

    public static function shouldRegisterNavigation(): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    public static function canViewAny(): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    public static function canDelete($record): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return static::isFlagEnabled() && \App\Support\RoleGate::adminOnly();
    }

    private static function isFlagEnabled(): bool
    {
        return (bool) config('features.email_campaigns');
    }

    /**
     * The segment_* fields are `dehydrated(false)` (they aren't real Campaign
     * columns) — Create/EditCampaign call this to assemble the actual
     * `segment` json filter CampaignSegmentResolver consumes.
     *
     * @param  array<string, mixed>  $rawState
     * @return array<string, mixed>
     */
    public static function buildSegmentFromRawState(array $rawState): array
    {
        return match ($rawState['segment_type'] ?? null) {
            'course' => ['type' => 'course', 'course_id' => $rawState['segment_course_id'] ?? null],
            'lead_stage' => ['type' => 'lead_stage', 'stage' => $rawState['segment_lead_stage'] ?? null],
            default => ['type' => 'all_subscribers'],
        };
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Кампания')
                    ->schema([
                        Forms\Components\TextInput::make('subject')
                            ->label('Тема письма')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TiptapEditor::make('body_html')
                            ->label('Текст письма')
                            ->profile('simple')
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Select::make('segment_type')
                            ->label('Сегмент')
                            ->options([
                                'all_subscribers' => 'Все подписчики (согласившиеся на email)',
                                'course' => 'Студенты курса',
                                'lead_stage' => 'Лиды на стадии воронки',
                            ])
                            ->default(fn ($record) => $record?->segment['type'] ?? 'all_subscribers')
                            ->live()
                            ->dehydrated(false)
                            ->required(),

                        Forms\Components\Select::make('segment_course_id')
                            ->label('Курс')
                            ->options(Course::pluck('title', 'id'))
                            ->default(fn ($record) => $record?->segment['course_id'] ?? null)
                            ->dehydrated(false)
                            ->visible(fn (Forms\Get $get) => $get('segment_type') === 'course'),

                        Forms\Components\TextInput::make('segment_lead_stage')
                            ->label('Ключ стадии (LeadStage.key)')
                            ->default(fn ($record) => $record?->segment['stage'] ?? null)
                            ->dehydrated(false)
                            ->visible(fn (Forms\Get $get) => $get('segment_type') === 'lead_stage'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Тема')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),

                Tables\Columns\TextColumn::make('recipients_count')
                    ->label('Получателей')
                    ->counts('recipients'),

                Tables\Columns\TextColumn::make('opened_count')
                    ->label('Открыто')
                    ->state(fn (Campaign $record) => $record->openedCount()),

                Tables\Columns\TextColumn::make('clicked_count')
                    ->label('Кликнуто')
                    ->state(fn (Campaign $record) => $record->clickedCount()),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Отправлено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('send')
                    ->label('Отправить')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (Campaign $record) => $record->status === Campaign::STATUS_DRAFT)
                    ->action(function (Campaign $record): void {
                        app(CampaignSender::class)->send($record);
                    }),
                Action::make('resend_non_openers')
                    ->label('Догнать неоткрывших')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (Campaign $record) => $record->status === Campaign::STATUS_SENDING || $record->status === Campaign::STATUS_SENT)
                    ->action(function (Campaign $record): void {
                        app(CampaignSender::class)->resend($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
