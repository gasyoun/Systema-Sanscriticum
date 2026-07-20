<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\ConditionalAccessGranter;
use App\Services\CuratorNotifier;
use App\Services\DebtorsReport;
use App\Services\InstallmentPlanCreator;
use App\Services\PromiseFulfillment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentPromisesRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentPromises';

    protected static ?string $title = 'Обещания оплатить';

    protected static ?string $icon = 'heroicon-o-hand-raised';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('course_id')
                ->label('Курс')
                ->options(fn () => Course::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\DatePicker::make('promised_at')
                ->label('Обещанная дата')
                ->required()
                ->native(false),
            Forms\Components\DatePicker::make('actual_paid_at')
                ->label('Факт. дата оплаты')
                ->native(false)
                ->helperText('Когда деньги реально пришли (если уже пришли). Может не совпадать с датой Payment-записи.'),
            Forms\Components\TextInput::make('amount')
                ->label('Сумма (₽)')
                ->numeric()
                ->minValue(0)
                ->helperText('Пусто = «оплатит весь текущий долг».'),
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    PaymentPromise::STATUS_ACTIVE => 'Активно',
                    PaymentPromise::STATUS_FULFILLED => 'Выполнено',
                    PaymentPromise::STATUS_EXPIRED => 'Просрочено',
                    PaymentPromise::STATUS_CANCELLED => 'Отменено',
                ])
                ->default(PaymentPromise::STATUS_ACTIVE)
                ->required(),
            Forms\Components\Textarea::make('note')
                ->label('Комментарий')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('promised_at'))
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->wrap(),
                Tables\Columns\TextColumn::make('promised_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actual_paid_at')
                    ->label('Факт. оплата')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((float) $state, 0, '.', ' ').' ₽'
                        : '—')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PaymentPromise::STATUS_ACTIVE => 'Активно',
                        PaymentPromise::STATUS_FULFILLED => 'Выполнено',
                        PaymentPromise::STATUS_EXPIRED => 'Просрочено',
                        PaymentPromise::STATUS_CANCELLED => 'Отменено',
                        default => $state ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        PaymentPromise::STATUS_ACTIVE => 'warning',
                        PaymentPromise::STATUS_FULFILLED => 'success',
                        PaymentPromise::STATUS_EXPIRED => 'danger',
                        PaymentPromise::STATUS_CANCELLED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('note')
                    ->label('Комментарий')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('fulfilled_payment_id')
                    ->label('Платёж')
                    ->formatStateUsing(fn ($state) => $state ? '#'.$state : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('installment_group_id')
                    ->label('План')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? '#'.substr($state, 0, 8) : null)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('access_state')
                    ->label('Доступ')
                    ->badge()
                    ->state(fn (PaymentPromise $r): string => Payment::query()
                        ->where('linked_promise_id', $r->id)
                        ->where('is_conditional', true)
                        ->exists() ? 'open' : 'closed')
                    ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'открыт' : '—')
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создано')
                    ->date('d.m.Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Новое обещание')
                    ->icon('heroicon-o-plus'),
                Tables\Actions\Action::make('create_installment')
                    ->label('Рассрочка')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->modalHeading('Новая рассрочка')
                    ->modalDescription('Каждая строка — отдельное обещание оплаты. Все строки объединяются в один план рассрочки.')
                    ->modalWidth('2xl')
                    ->fillForm(function (): array {
                        $start = now()->addDays(7);

                        return [
                            'schedule' => [
                                ['promised_at' => $start->copy()->toDateString(), 'amount' => null],
                                ['promised_at' => $start->copy()->addMonth()->toDateString(), 'amount' => null],
                                ['promised_at' => $start->copy()->addMonths(2)->toDateString(), 'amount' => null],
                            ],
                        ];
                    })
                    ->form([
                        Forms\Components\Select::make('course_id')
                            ->label('Курс')
                            ->options(fn () => Course::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id'))
                            ->searchable()
                            ->required(),
                        Forms\Components\Repeater::make('schedule')
                            ->label('График платежей')
                            ->schema([
                                Forms\Components\DatePicker::make('promised_at')
                                    ->label('Обещанная дата')
                                    ->required()
                                    ->native(false)
                                    ->minDate(now()->subDays(1)),
                                Forms\Components\TextInput::make('amount')
                                    ->label('Сумма (₽)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),
                                Forms\Components\DatePicker::make('actual_paid_at')
                                    ->label('Факт. дата (если уже пришли деньги)')
                                    ->native(false)
                                    ->helperText('Можно оставить пустым — менеджер заполнит позже.'),
                                Forms\Components\Grid::make(2)->schema([
                                    Forms\Components\TextInput::make('block_from')
                                        ->label('Откр. с блока №')
                                        ->numeric()
                                        ->minValue(1)
                                        ->helperText('Заполните оба, чтобы открыть диапазон при сохранении.'),
                                    Forms\Components\TextInput::make('block_to')
                                        ->label('по №')
                                        ->numeric()
                                        ->minValue(1),
                                ]),
                            ])
                            ->columns(2)
                            ->minItems(2)
                            ->defaultItems(3)
                            ->addActionLabel('Добавить платёж')
                            ->reorderable(false)
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label('Комментарий ко всему плану')
                            ->rows(3),
                    ])
                    ->action(function (array $data): void {
                        $user = $this->getOwnerRecord();
                        $course = Course::find($data['course_id']);
                        if (! $course) {
                            Notification::make()->title('Курс не найден')->danger()->send();

                            return;
                        }

                        $schedule = array_values($data['schedule'] ?? []);
                        $result = app(InstallmentPlanCreator::class)->create(
                            $user,
                            $course,
                            array_map(fn ($row) => [
                                'promised_at' => $row['promised_at'],
                                'amount' => $row['amount'],
                                'actual_paid_at' => $row['actual_paid_at'] ?? null,
                            ], $schedule),
                            isset($data['note']) && trim((string) $data['note']) !== '' ? $data['note'] : null,
                        );

                        $grantedTotal = 0;
                        $granter = app(ConditionalAccessGranter::class);
                        foreach ($result['promises'] as $i => $promise) {
                            $row = $schedule[$i] ?? null;
                            if ($row === null) {
                                continue;
                            }
                            $from = $row['block_from'] ?? null;
                            $to = $row['block_to'] ?? null;
                            if ($from === null || $from === '' || $to === null || $to === '') {
                                continue;
                            }
                            $from = (int) $from;
                            $to = (int) $to;
                            if ($from > $to) {
                                [$from, $to] = [$to, $from];
                            }
                            $blocks = range($from, $to);
                            $granter->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, $blocks);
                            $grantedTotal += count($blocks);
                        }

                        $body = 'Создано платежей: '.count($result['promises']);
                        if ($grantedTotal > 0) {
                            $body .= ". Открыт доступ к {$grantedTotal} блоку(ам).";
                        }

                        Notification::make()
                            ->title('Рассрочка создана')
                            ->body($body)
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('fulfil')
                    ->label('Подтвердить оплату')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PaymentPromise $r) => $r->status === PaymentPromise::STATUS_ACTIVE)
                    ->modalHeading('Подтверждение оплаты по обещанию')
                    ->modalDescription('Будет создан Payment, покрывающий указанные блоки. Обещание закроется как выполненное.')
                    ->fillForm(function (PaymentPromise $r): array {
                        $report = app(DebtorsReport::class);
                        $refs = $report->referenceBlocks();
                        $refBlock = $refs->get((int) $r->course_id);
                        $blocks = $refBlock
                            ? Debtors::debtBlocks((int) $r->user_id, (int) $r->course_id, (int) $refBlock->number)
                            : [];
                        $info = ! empty($blocks)
                            ? $report->computeDebtAmount($r->user, (int) $r->course_id, $blocks)
                            : ['amount' => null, 'missing_tariffs' => 0];

                        return [
                            'amount' => $r->amount !== null ? (float) $r->amount : $info['amount'],
                            'start_block' => ! empty($blocks) ? min($blocks) : ($refBlock?->number ?? 1),
                            'end_block' => ! empty($blocks) ? max($blocks) : ($refBlock?->number ?? 1),
                            'transaction_id' => 'promise_#'.$r->id,
                            'silent' => false,
                        ];
                    })
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма (₽)')->numeric()->required()->minValue(1),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('start_block')
                                ->label('Блок с №')->numeric()->required()->minValue(1),
                            Forms\Components\TextInput::make('end_block')
                                ->label('по №')->numeric()->required()->minValue(1),
                        ]),
                        Forms\Components\TextInput::make('transaction_id')
                            ->label('Идентификатор транзакции')->maxLength(255),
                        Forms\Components\Toggle::make('silent')
                            ->label('Не уведомлять студента в TG')
                            ->helperText('Включите, если фиксируете факт оплаты задним числом или платёж ещё не пришёл.'),
                    ])
                    ->action(function (PaymentPromise $r, array $data): void {
                        app(PromiseFulfillment::class)->fulfil($r, $data, (bool) ($data['silent'] ?? false));
                        Notification::make()->title('Платёж создан, обещание закрыто')->success()->send();
                    }),
                Tables\Actions\Action::make('cancel_promise')
                    ->label('Отменить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PaymentPromise $r) => $r->status === PaymentPromise::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (PaymentPromise $r): void {
                        $r->update([
                            'status' => PaymentPromise::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                        ]);
                        app(CuratorNotifier::class)->promiseCancelled($r);
                        Notification::make()->title('Договорённость отменена')->warning()->send();
                    }),
                Tables\Actions\Action::make('grant_access')
                    ->label('Открыть доступ')
                    ->icon('heroicon-o-lock-open')
                    ->color('info')
                    ->visible(fn (PaymentPromise $r) => $r->status === PaymentPromise::STATUS_ACTIVE
                        && ! ($r->user?->isUnreliable() ?? false)
                        && ! app(ConditionalAccessGranter::class)->hasActiveGrant($r))
                    ->modalHeading('Открыть доступ под обещание')
                    ->form([
                        Forms\Components\Radio::make('access_mode')
                            ->label('Что открыть')
                            ->options([
                                ConditionalAccessGranter::MODE_FULL => 'Весь курс',
                                ConditionalAccessGranter::MODE_BLOCKS => 'Конкретные блоки',
                            ])
                            ->default(ConditionalAccessGranter::MODE_BLOCKS)
                            ->inline()
                            ->live(),
                        Forms\Components\TextInput::make('access_blocks')
                            ->label('Номера блоков (через запятую или диапазон 1-3)')
                            ->placeholder('5, 6, 7')
                            ->visible(fn (Forms\Get $get): bool => $get('access_mode') === ConditionalAccessGranter::MODE_BLOCKS),
                    ])
                    ->action(function (PaymentPromise $r, array $data): void {
                        $mode = (string) ($data['access_mode'] ?? ConditionalAccessGranter::MODE_BLOCKS);
                        $blocks = $mode === ConditionalAccessGranter::MODE_BLOCKS
                            ? self::parseBlockNumbers((string) ($data['access_blocks'] ?? ''))
                            : [];
                        app(ConditionalAccessGranter::class)->grantForPromise($r, $mode, $blocks);
                        Notification::make()->title('Доступ открыт')->success()->send();
                    }),
                Tables\Actions\Action::make('revoke_access')
                    ->label('Отозвать доступ')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (PaymentPromise $r) => app(ConditionalAccessGranter::class)->hasActiveGrant($r))
                    ->requiresConfirmation()
                    ->modalDescription('Все conditional-платежи будут удалены. Студент получит уведомление в TG, email, VK и Max — все каналы, по которым у нас есть его контакт.')
                    ->form([
                        Forms\Components\Textarea::make('reason_note')
                            ->label('Комментарий для студента (необязательно)')
                            ->rows(2),
                    ])
                    ->action(function (PaymentPromise $r, array $data): void {
                        $result = app(ConditionalAccessGranter::class)->revokeAndNotifyAllChannels(
                            $r,
                            auth()->user(),
                            ! empty($data['reason_note']) ? (string) $data['reason_note'] : null,
                        );
                        $channelsLine = implode(', ', array_map(
                            fn ($ch, $st) => "{$ch}: {$st}",
                            array_keys($result['channels']),
                            $result['channels'],
                        ));
                        Notification::make()
                            ->title('Доступ отозван')
                            ->body("Удалено платежей: {$result['deleted']}. Каналы — {$channelsLine}.")
                            ->warning()
                            ->send();
                    }),
                Tables\Actions\Action::make('cancel_installment')
                    ->label('Отменить всю рассрочку')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (PaymentPromise $r) => $r->installment_group_id !== null
                        && $r->status === PaymentPromise::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->modalDescription('Все активные обещания этого плана будут отменены. Уже выполненные платежи не затрагиваются.')
                    ->action(function (PaymentPromise $r): void {
                        $count = app(InstallmentPlanCreator::class)->cancelGroup($r->installment_group_id);
                        Notification::make()
                            ->title('Рассрочка отменена')
                            ->body("Отменено обещаний: {$count}")
                            ->warning()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * Парсит строку «5, 6, 7» / «1-3, 5» в массив номеров блоков.
     *
     * @return list<int>
     */
    public static function parseBlockNumbers(string $input): array
    {
        $result = [];
        foreach (preg_split('/\s*,\s*/', trim($input)) ?: [] as $chunk) {
            if ($chunk === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $chunk, $m)) {
                $from = (int) $m[1];
                $to = (int) $m[2];
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                for ($n = $from; $n <= $to; $n++) {
                    $result[] = $n;
                }
            } elseif (ctype_digit($chunk)) {
                $result[] = (int) $chunk;
            }
        }

        return array_values(array_unique($result));
    }
}
