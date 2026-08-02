<?php

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Models\Certificate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Вехи автовыдачи сертификатов курса: диапазон блоков + параметры сертификата.
 * Средний курс — одна итоговая веха за все 4 блока; длинные (грамматики)
 * настраивают промежуточные («Деванагари», блоки 1–2). Выдаёт и уведомляет
 * ежедневная certificates:issue-milestones (гейт — в настройках маркетинга).
 */
class CertificateMilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificateMilestones';

    protected static ?string $title = 'Вехи сертификатов';

    protected static ?string $recordTitleAttribute = 'title';

    /** Блоки курса для селектов диапазона: number => «№N — название». */
    private function blockOptions(): array
    {
        return $this->getOwnerRecord()
            ->blocks()
            ->get()
            ->mapWithKeys(fn ($b) => [$b->number => '№'.$b->number.($b->title ? " — {$b->title}" : '')])
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название вехи')
                    ->helperText('Служебное, видно только в админке. Например: «Деванагари, блоки 1–2».')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('certificate_title')
                    ->label('Название в сертификате')
                    ->helperText('Символ | — перенос строки. Пусто — берётся название курса.')
                    ->columnSpanFull(),

                Forms\Components\Select::make('start_block')
                    ->label('С блока')
                    ->options($this->blockOptions())
                    ->required(),

                Forms\Components\Select::make('end_block')
                    ->label('По блок (его конец — триггер выдачи)')
                    ->options($this->blockOptions())
                    ->required()
                    // Диапазон не должен быть вывернут: end >= start.
                    ->rule(fn (Forms\Get $get) => 'gte:'.($get('start_block') ?? 0)),

                Forms\Components\Select::make('template')
                    ->label('Шаблон (роспись)')
                    ->options(Certificate::templateOptions())
                    ->default('gasuns')
                    ->required()
                    ->live()
                    ->helperText(fn (Forms\Get $get) => $get('template') === 'sanka'
                        ? 'Экзаменационный шаблон требует ручных баллов — автовыдача по этой вехе работать не будет, только ручная.'
                        : null),

                Forms\Components\Toggle::make('auto_issue_enabled')
                    ->label('Автовыдача включена')
                    ->default(true),

                Forms\Components\Toggle::make('is_active')
                    ->label('Веха активна')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_block')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Веха')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('start_block')
                    ->label('Блоки')
                    ->formatStateUsing(fn ($record) => $record->start_block === $record->end_block
                        ? "№{$record->start_block}"
                        : "№{$record->start_block}–{$record->end_block}"),

                Tables\Columns\TextColumn::make('template')
                    ->label('Шаблон')
                    ->formatStateUsing(fn ($state) => Certificate::TEMPLATES[$state]['label'] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === 'sanka' ? 'warning' : 'success'),

                Tables\Columns\IconColumn::make('auto_issue_enabled')
                    ->label('Авто')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),

                Tables\Columns\TextColumn::make('certificates_count')
                    ->label('Выдано')
                    ->counts('certificates'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить веху')
                    ->icon('heroicon-o-plus'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
