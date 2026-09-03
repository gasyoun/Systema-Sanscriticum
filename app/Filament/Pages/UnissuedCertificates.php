<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\UnissuedCertificatesExporter;
use App\Models\CertificateMilestone;
use App\Services\UnissuedCertificatesReport;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * H3914: Filament-отчёт «Невыданные дипломы и справки» — read-only список
 * (студент × веха × итерация), где документ положен, но ещё не выдан, плюс
 * CSV-выгрузка для куратора. Доступ — как у «Должников» (RoleGate::adminOnly).
 * Выдача со страницы НЕ делается сознательно: выдача документов — действие
 * вех/карточки курса, отчёт только показывает дыру (категория F).
 */
class UnissuedCertificates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Невыданные дипломы';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Невыданные дипломы и справки';

    protected static ?string $slug = 'unissued-certificates';

    protected static string $view = 'filament.pages.unissued-certificates';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(UnissuedCertificatesReport::class)->query())
            ->columns([
                TextColumn::make('id')
                    ->label('ID студента')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('telegram_id')
                    ->label('TG')
                    ->formatStateUsing(fn ($state) => $state ? 'да' : '')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vk_id')
                    ->label('VK')
                    ->formatStateUsing(fn ($state) => $state ? 'да' : '')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('course_title')
                    ->label('Курс')
                    ->state(fn ($record) => $this->courseTitles()[$record->course_id] ?? ''),
                TextColumn::make('document_type')
                    ->label('Документ')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CertificateMilestone::DOCUMENT_TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === CertificateMilestone::DOC_SPRAVKA ? 'info' : 'success'),
                TextColumn::make('milestone_title')
                    ->label('Веха'),
                TextColumn::make('group_name')
                    ->label('Группа')
                    ->placeholder('—'),
                TextColumn::make('trigger_date')
                    ->label('Триггер созрел')
                    ->formatStateUsing(fn (?string $state): ?string => $state
                        ? Carbon::parse($state)->format('d.m.Y')
                        : null),
                TextColumn::make('occurrence')
                    ->label('Итерация')
                    ->formatStateUsing(fn (int $state): string => $state > 1 ? '№'.$state : '—'),
            ])
            ->filters([
                SelectFilter::make('course')
                    ->label('Курс')
                    ->options(fn (): array => $this->courseTitles())
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $courseId) => $q->where('d.course_id', $courseId),
                    )),
                SelectFilter::make('document_type')
                    ->label('Тип документа')
                    ->options(CertificateMilestone::DOCUMENT_TYPES)
                    ->attribute('d.document_type'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(UnissuedCertificatesExporter::class)
                    ->label('Скачать CSV'),
            ])
            ->defaultSort('users.name', 'asc')
            ->emptyStateHeading('Невыданных документов нет')
            ->emptyStateDescription('Либо все созревшие вехи закрыты документами, либо активных автовыдаваемых вех ещё нет.');
    }

    /** @return array<int, string> course_id => title */
    private function courseTitles(): array
    {
        return app(UnissuedCertificatesReport::class)->courseTitles();
    }
}
