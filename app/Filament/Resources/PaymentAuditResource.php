<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentAuditResource\Pages;
use App\Models\PaymentAudit;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentAuditResource extends Resource
{
    protected static ?string $model = PaymentAudit::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 81;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Журнал финансов';

    protected static ?string $pluralModelLabel = 'Журнал финансов';

    protected static ?string $modelLabel = 'Запись журнала';

    /** Аудит финансов — только для супер-админа. */
    public static function canViewAny(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    // Лог неизменяем: ни создавать, ни править, ни удалять вручную нельзя.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['admin', 'payment.user']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->size('sm')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('admin_name')
                    ->label('Кто')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->description(fn (PaymentAudit $r): ?string => $r->admin?->email),

                Tables\Columns\TextColumn::make('action')
                    ->label('Действие')
                    ->badge()
                    ->formatStateUsing(fn (PaymentAudit $r): string => $r->actionLabel())
                    ->color(fn (string $state): string => match ($state) {
                        PaymentAudit::ACTION_CREATED => 'success',
                        PaymentAudit::ACTION_UPDATED => 'warning',
                        PaymentAudit::ACTION_DELETED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('payment_id')
                    ->label('Платёж')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '—')
                    ->description(fn (PaymentAudit $r): string => $r->payment?->user?->name ?? 'платёж удалён')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->color(fn (PaymentAudit $r): string => (float) $r->amount < 0 ? 'danger' : 'gray')
                    ->alignment(\Filament\Support\Enums\Alignment::End),

                Tables\Columns\TextColumn::make('changes')
                    ->label('Изменения')
                    ->formatStateUsing(fn (PaymentAudit $r): string => $r->summary())
                    ->wrap()
                    ->size('sm')
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('Действие')
                    ->options([
                        PaymentAudit::ACTION_CREATED => 'Создание',
                        PaymentAudit::ACTION_UPDATED => 'Изменение',
                        PaymentAudit::ACTION_DELETED => 'Удаление',
                    ]),

                Tables\Filters\SelectFilter::make('admin_id')
                    ->label('Админ')
                    ->relationship('admin', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->label('Период')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('С даты')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Forms\Components\DatePicker::make('until')
                            ->label('По дату')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'С '.\Illuminate\Support\Carbon::parse($data['from'])->format('d.m.Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'По '.\Illuminate\Support\Carbon::parse($data['until'])->format('d.m.Y');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentAudits::route('/'),
        ];
    }
}
