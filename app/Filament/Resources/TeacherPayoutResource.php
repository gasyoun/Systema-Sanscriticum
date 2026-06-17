<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Exports\TeacherPayoutsExporter;
use App\Filament\Resources\TeacherPayoutResource\Pages;
use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\TeacherSalaryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TeacherPayoutResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = TeacherPayout::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'История выплат';

    protected static ?string $modelLabel = 'выплата';

    protected static ?string $pluralModelLabel = 'Выплаты преподавателям';

    /**
     * Последние N месяцев в формате YYYY-MM => «Июнь 2026» для select'ов.
     *
     * @return array<string, string>
     */
    public static function periodOptions(int $count = 18): array
    {
        $options = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < $count; $i++) {
            $key = $cursor->format('Y-m');
            $options[$key] = $cursor->translatedFormat('F Y');
            $cursor->subMonth();
        }

        return $options;
    }

    /**
     * Короткая расшифровка структурной разбивки для тултипа в таблице.
     */
    public static function breakdownTooltip(TeacherPayout $payout): ?string
    {
        $b = $payout->breakdown;
        if (empty($b)) {
            return null;
        }

        $parts = [];
        if (isset($b['block_number'])) {
            $parts[] = 'блок '.$b['block_number'];
        }
        if (! empty($b['block_period']['starts_at']) || ! empty($b['block_period']['ends_at'])) {
            $fmt = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : '…';
            $parts[] = $fmt($b['block_period']['starts_at'] ?? null).'–'.$fmt($b['block_period']['ends_at'] ?? null);
        }
        if (! empty($b['base_revenue'])) {
            $parts[] = 'база '.number_format((float) $b['base_revenue'], 0, '.', ' ').' ₽';
        }
        if (! empty($b['payments'])) {
            $parts[] = 'оплат: '.count($b['payments']);
        }
        if (isset($b['coefficient'])) {
            $parts[] = 'коэф '.$b['coefficient'].'%';
        }
        if (isset($b['teacher_percent'])) {
            $parts[] = 'процент '.$b['teacher_percent'].'%';
        }
        if (! empty($b['extras_total'])) {
            $parts[] = 'допзанятия '.number_format((float) $b['extras_total'], 0, '.', ' ').' ₽';
        }
        if (! empty($b['surcharge'])) {
            $parts[] = 'доплата '.number_format((float) $b['surcharge'], 0, '.', ' ').' ₽';
        }
        if (! empty($b['deduction'])) {
            $parts[] = 'удержание −'.number_format((float) $b['deduction'], 0, '.', ' ').' ₽';
        }

        return implode(' · ', $parts);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('teacher_id')
                ->label('Преподаватель')
                ->options(fn () => Teacher::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('amount')
                ->label('Сумма выплаты (₽)')
                ->numeric()
                ->required(),
            Forms\Components\DatePicker::make('paid_at')
                ->label('Дата выплаты')
                ->native(false)
                ->default(now())
                ->required(),
            Forms\Components\Select::make('period_month')
                ->label('За период')
                ->options(self::periodOptions())
                ->default(now()->format('Y-m'))
                ->helperText('За какой месяц эта выплата — для отчёта по периодам.'),
            Forms\Components\Select::make('course_id')
                ->label('Курс (необязательно)')
                ->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id'))
                ->searchable()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                    // Снимок ставки курса на момент выплаты (B6).
                    $course = $state ? Course::find($state) : null;
                    $set('salary_type', $course?->salary_type);
                    $set('salary_value', $course?->salary_value);
                }),
            Forms\Components\Select::make('salary_type')
                ->label('Схема оплаты (снимок)')
                ->options(TeacherSalaryService::salaryTypeLabels())
                ->helperText('Фиксируется на момент выплаты, чтобы смена ставки курса не переписывала прошлые периоды.'),
            Forms\Components\TextInput::make('salary_value')
                ->label('Ставка (снимок)')
                ->numeric(),
            Forms\Components\Textarea::make('comment')
                ->label('Комментарий')
                ->rows(2)
                ->columnSpanFull()
                ->placeholder('Например: ЗП за март'),
            Forms\Components\Toggle::make('post_to_finance')
                ->label('Провести в Финансах')
                ->dehydrated(false)
                ->default(fn (?TeacherPayout $record): bool => $record ? (bool) $record->payment_id : true)
                ->helperText('Создаст/обновит транзакцию-отток в «Финансах» (тип «Выплата ЗП»). Выключите, чтобы убрать её из Финансов.')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('teacher.name')
                    ->label('Преподаватель')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Дата выплаты')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_month')
                    ->label('Период')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $state)->translatedFormat('F Y')
                        : '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('salary_type')
                    ->label('Схема (снимок)')
                    ->formatStateUsing(fn (?string $state): string => TeacherSalaryService::salaryTypeLabel($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('breakdown')
                    ->label('Расчёт по блоку')
                    ->boolean()
                    ->getStateUsing(fn (TeacherPayout $r): bool => ! empty($r->breakdown))
                    ->tooltip(fn (TeacherPayout $r): ?string => self::breakdownTooltip($r))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('teacher_id')
                    ->label('Преподаватель')
                    ->options(fn () => Teacher::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('period_month')
                    ->label('Период')
                    ->options(self::periodOptions()),
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id'))
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ExportBulkAction::make()
                        ->exporter(TeacherPayoutsExporter::class)
                        ->label('Экспорт'),
                ]),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(TeacherPayoutsExporter::class)
                    ->label('Экспорт'),
            ])
            ->defaultSort('paid_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherPayouts::route('/'),
            'create' => Pages\CreateTeacherPayout::route('/create'),
            'edit' => Pages\EditTeacherPayout::route('/{record}/edit'),
        ];
    }
}
