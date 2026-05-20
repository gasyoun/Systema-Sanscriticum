<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\DebtorsExporter;
use App\Filament\Resources\UserResource;
use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Services\InstallmentPlanCreator;
use App\Services\PromiseFulfillment;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Debtors extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<int, Payment|null> */
    private static array $paymentCache = [];

    /** @var array<string, list<int>>  ключ "{course_id}:{ref}" */
    private static array $courseBlockNumbersCache = [];

    /** @var array<string, \Illuminate\Database\Eloquent\Collection<int, Payment>>  ключ "{user_id}:{course_id}" */
    private static array $userCoursePaymentsCache = [];

    /** @var array<string, array{amount:?float, missing:int}>  ключ "{user_id}:{course_id}" */
    private static array $debtAmountCache = [];

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Должники';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Должники';

    protected static ?string $slug = 'debtors';

    protected static string $view = 'filament.pages.debtors';

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
        $report = app(DebtorsReport::class);
        $blocksLookup = $report->blocksLookup();
        $courseTitles = $report->courseTitles();
        $debtors = $this;

        return $table
            ->query($report->query())
            ->recordTitleAttribute('name')
            ->recordTitle(fn (Model $r) => $r->name ?: $r->email)
            // Один юзер может встречаться по нескольким курсам — ключ строки
            // должен быть составной, иначе Filament схлопнет дубли.
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->description(fn (Model $r) => trim(($r->email ?? '').' · #'.$r->id))
                    ->weight('bold')
                    ->color('primary')
                    ->searchable(['name', 'email', 'phone'])
                    ->wrap()
                    ->action($this->viewUserCardAction()),

                Tables\Columns\TextColumn::make('course_id')
                    ->label('Курс')
                    ->getStateUsing(fn (Model $r) => $courseTitles[$r->course_id] ?? '—')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('ref_block_number')
                    ->label('№ блока')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('block_dates')
                    ->label('Период блока')
                    ->getStateUsing(function (Model $r) use ($blocksLookup): string {
                        $block = $blocksLookup[$r->course_id.':'.$r->ref_block_number] ?? null;
                        if (! $block instanceof CourseBlock || ! $block->starts_at || ! $block->ends_at) {
                            return '—';
                        }

                        return $block->starts_at->format('d.m').' – '.$block->ends_at->format('d.m.Y');
                    }),

                Tables\Columns\TextColumn::make('debt_type')
                    ->label('Тип долга')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'not_renewed' => 'Не продлил',
                        'no_payment' => 'Без оплат',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'not_renewed' => 'warning',
                        'no_payment' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('debt_blocks')
                    ->label('В долгу')
                    ->getStateUsing(fn (Model $r): string => self::debtBlocksFormatted(
                        (int) $r->id,
                        (int) $r->course_id,
                        (int) $r->ref_block_number,
                    ))
                    ->wrap()
                    ->tooltip('Все начавшиеся блоки курса, не покрытые ни одним paid-платежом'),

                Tables\Columns\TextColumn::make('debt_amount')
                    ->label('Сумма долга')
                    ->alignRight()
                    ->getStateUsing(function (Model $r) use ($report): string {
                        $info = self::debtAmountInfo($r, $report);
                        if ($info['amount'] === null) {
                            return '—';
                        }
                        $formatted = number_format($info['amount'], 0, '.', ' ').' ₽';

                        return $info['missing'] > 0 ? '≈ '.$formatted : $formatted;
                    })
                    ->tooltip(function (Model $r) use ($report): ?string {
                        $info = self::debtAmountInfo($r, $report);
                        if ($info['amount'] === null) {
                            return 'У курса нет ни block-, ни full-тарифа — заведите тарифы в админке.';
                        }
                        if ($info['missing'] > 0) {
                            return "≈ {$info['missing']} блок(ов) оценены по средней цене (точного тарифа нет).";
                        }

                        return null;
                    })
                    ->color(function (Model $r) use ($report): string {
                        $info = self::debtAmountInfo($r, $report);

                        return ($info['amount'] ?? 0) >= 10000 ? 'danger' : 'warning';
                    }),

                Tables\Columns\TextColumn::make('promise')
                    ->label('Обещание')
                    ->badge()
                    ->getStateUsing(function (Model $r) use ($report): ?string {
                        $promise = $report->promiseFor((int) $r->id, (int) $r->course_id);
                        if (! $promise) {
                            return null;
                        }
                        $date = $promise->promised_at?->format('d.m.Y') ?? '—';

                        return $promise->isOverdue() ? "просрочено {$date}" : "до {$date}";
                    })
                    ->color(function (Model $r) use ($report): string {
                        $promise = $report->promiseFor((int) $r->id, (int) $r->course_id);
                        if (! $promise) {
                            return 'gray';
                        }

                        return $promise->isOverdue() ? 'danger' : 'warning';
                    })
                    ->tooltip(function (Model $r) use ($report): ?string {
                        $promise = $report->promiseFor((int) $r->id, (int) $r->course_id);
                        if (! $promise) {
                            return null;
                        }
                        $parts = [];
                        if ($promise->amount !== null) {
                            $parts[] = number_format((float) $promise->amount, 0, '.', ' ').' ₽';
                        }
                        if (! empty($promise->note)) {
                            $parts[] = $promise->note;
                        }

                        return empty($parts) ? null : implode(' · ', $parts);
                    }),

                Tables\Columns\TextColumn::make('last_payment_id')
                    ->label('Последняя оплата')
                    ->getStateUsing(function (Model $r): string {
                        if (! $r->last_payment_id) {
                            return '—';
                        }
                        $p = self::$paymentCache[$r->last_payment_id] ??= Payment::find($r->last_payment_id);
                        if (! $p) {
                            return '—';
                        }
                        $amount = number_format((float) $p->amount, 0, '.', ' ').' ₽';
                        $date = $p->created_at?->format('d.m.Y') ?? '';

                        return trim($date.' · '.$amount, ' ·');
                    })
                    ->description(function (Model $r): ?string {
                        if (! $r->last_payment_id) {
                            return null;
                        }
                        $p = self::$paymentCache[$r->last_payment_id] ??= Payment::find($r->last_payment_id);
                        if (! $p) {
                            return null;
                        }

                        return self::formatPaidBlocks($p->start_block, $p->end_block);
                    })
                    ->tooltip('Дата, сумма и блок(и) последнего успешного платежа за курс'),

                Tables\Columns\IconColumn::make('telegram_id')
                    ->label('TG')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('vk_id')
                    ->label('VK')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Активность')
                    ->since()
                    ->placeholder('—')
                    ->color(function ($state): string {
                        if (! $state) {
                            return 'gray';
                        }
                        $dt = $state instanceof \Carbon\CarbonInterface
                            ? $state
                            : \Illuminate\Support\Carbon::parse($state);

                        return $dt->gt(now()->subDays(7)) ? 'success' : 'gray';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->multiple()
                    ->options($courseTitles)
                    ->query(function ($query, array $data) {
                        if (! empty($data['values'])) {
                            $query->whereIn('d.course_id', $data['values']);
                        }
                    }),

                Tables\Filters\SelectFilter::make('debt_type')
                    ->label('Тип долга')
                    ->options([
                        'not_renewed' => 'Не продлил',
                        'no_payment' => 'Без оплат',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->where('d.debt_type', $data['value']);
                        }
                    }),

                Tables\Filters\Filter::make('has_telegram')
                    ->label('Есть Telegram')
                    ->query(fn ($query) => $query->whereNotNull('users.telegram_id')),

                Tables\Filters\Filter::make('has_vk')
                    ->label('Есть VK')
                    ->query(fn ($query) => $query->whereNotNull('users.vk_id')),

                Tables\Filters\TernaryFilter::make('with_active_promise')
                    ->label('Обещание оплатить')
                    ->placeholder('Все')
                    ->trueLabel('С обещанием')
                    ->falseLabel('Без обещания')
                    ->queries(
                        true: fn ($query) => $query->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('payment_promises')
                                ->whereColumn('payment_promises.user_id', 'users.id')
                                ->whereColumn('payment_promises.course_id', 'd.course_id')
                                ->where('payment_promises.status', PaymentPromise::STATUS_ACTIVE);
                        }),
                        false: fn ($query) => $query->whereNotExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('payment_promises')
                                ->whereColumn('payment_promises.user_id', 'users.id')
                                ->whereColumn('payment_promises.course_id', 'd.course_id')
                                ->where('payment_promises.status', PaymentPromise::STATUS_ACTIVE);
                        }),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                $this->promiseAction(),
                $this->installmentAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    $this->sendReminderBulkAction(),
                    Tables\Actions\ExportBulkAction::make()
                        ->exporter(DebtorsExporter::class)
                        ->label('Экспорт'),
                ]),
            ])
            ->defaultSort('d.course_id', 'asc')
            ->emptyStateHeading('Должников не найдено')
            ->emptyStateDescription('Либо нет активных курсов с reference-блоком, либо все студенты платежами покрыты.');
    }

    /**
     * @return array{amount:?float, missing:int}
     */
    private static function debtAmountInfo(Model $r, DebtorsReport $report): array
    {
        $key = $r->id.':'.$r->course_id;
        if (isset(self::$debtAmountCache[$key])) {
            return self::$debtAmountCache[$key];
        }

        $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number);
        $info = $report->computeDebtAmount(User::find($r->id), (int) $r->course_id, $blocks);

        return self::$debtAmountCache[$key] = [
            'amount' => $info['amount'],
            'missing' => $info['missing_tariffs'],
        ];
    }

    private static function debtBlocksFormatted(int $userId, int $courseId, int $refNumber): string
    {
        $numbers = self::debtBlocks($userId, $courseId, $refNumber);
        if (empty($numbers)) {
            return '—';
        }

        return DebtorsReport::formatBlockRanges($numbers);
    }

    /**
     * @return list<int>
     */
    public static function debtBlocks(int $userId, int $courseId, int $refNumber): array
    {
        $blockKey = $courseId.':'.$refNumber;
        $allBlocks = self::$courseBlockNumbersCache[$blockKey] ??= CourseBlock::query()
            ->where('course_id', $courseId)
            ->where('number', '<=', $refNumber)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();

        if (empty($allBlocks)) {
            return [];
        }

        $payKey = $userId.':'.$courseId;
        $payments = self::$userCoursePaymentsCache[$payKey] ??= Payment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['paid', 'success'])
            ->get(['start_block', 'end_block']);

        $debt = [];
        foreach ($allBlocks as $n) {
            $covered = false;
            foreach ($payments as $p) {
                if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $n)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $debt[] = $n;
            }
        }

        return $debt;
    }

    private static function formatPaidBlocks(?int $start, ?int $end): string
    {
        if ($start === null && $end === null) {
            return 'весь курс';
        }
        if ($start !== null && $end !== null) {
            return $start === $end ? "блок №{$start}" : "блоки №{$start}–{$end}";
        }
        if ($start !== null) {
            return "с блока №{$start}";
        }

        return "по блок №{$end}";
    }

    private function viewUserCardAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('view_user_card')
            ->modalHeading(fn (Model $r): string => 'Карточка студента: '.($r->name ?: $r->email))
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->extraModalFooterActions([
                Tables\Actions\Action::make('open_full_card')
                    ->label('Открыть полную карточку')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (Model $r): string => UserResource::getUrl('edit', ['record' => $r->id]))
                    ->openUrlInNewTab(),
            ])
            ->infolist([
                Infolists\Components\Section::make()
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('email')->label('Email')->copyable(),
                        Infolists\Components\TextEntry::make('phone')->label('Телефон')->copyable()->placeholder('—'),
                        Infolists\Components\TextEntry::make('global_status')
                            ->label('Статус')
                            ->badge()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('role')->label('Роль')->placeholder('—'),
                        Infolists\Components\IconEntry::make('telegram_id')->label('Telegram')->boolean(),
                        Infolists\Components\IconEntry::make('vk_id')->label('VK')->boolean(),
                        Infolists\Components\TextEntry::make('prana_balance')
                            ->label('Прана')
                            ->numeric()
                            ->placeholder('0'),
                        Infolists\Components\TextEntry::make('last_activity_at')
                            ->label('Последняя активность')
                            ->since()
                            ->placeholder('—'),
                    ]),
                Infolists\Components\Section::make('Примечание')
                    ->collapsed(fn (Model $r): bool => empty($r->note))
                    ->schema([
                        Infolists\Components\TextEntry::make('note')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->prose(),
                    ]),
            ]);
    }

    private function promiseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('promise')
            ->label(fn (Model $r): string => $this->existingActivePromise($r) ? 'Обещание' : 'Договориться')
            ->icon('heroicon-o-hand-raised')
            ->color(fn (Model $r): string => $this->existingActivePromise($r) ? 'warning' : 'primary')
            ->modalHeading(fn (Model $r): string => 'Договорённость по оплате — '.($r->name ?: $r->email))
            ->fillForm(function (Model $r): array {
                $existing = $this->existingActivePromise($r);
                if (! $existing) {
                    return ['promised_at' => now()->addDays(7)->toDateString()];
                }

                return [
                    'promised_at' => $existing->promised_at?->toDateString(),
                    'amount' => $existing->amount !== null ? (float) $existing->amount : null,
                    'note' => $existing->note,
                ];
            })
            ->form([
                Forms\Components\DatePicker::make('promised_at')
                    ->label('Дата обещанной оплаты')
                    ->required()
                    ->native(false)
                    ->minDate(now()->subDays(1)),
                Forms\Components\TextInput::make('amount')
                    ->label('Сумма (₽)')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Можно оставить пустым, если студент обещает закрыть весь долг.'),
                Forms\Components\Textarea::make('note')
                    ->label('Комментарий')
                    ->rows(3)
                    ->placeholder('Например: «получит зарплату 25-го, оплатит вечером»'),
            ])
            ->action(function (Model $r, array $data): void {
                $existing = $this->existingActivePromise($r);
                $payload = [
                    'promised_at' => $data['promised_at'],
                    'amount' => $data['amount'] !== null && $data['amount'] !== '' ? $data['amount'] : null,
                    'note' => $data['note'] ?? null,
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    PaymentPromise::create(array_merge($payload, [
                        'user_id' => $r->id,
                        'course_id' => $r->course_id,
                        'status' => PaymentPromise::STATUS_ACTIVE,
                    ]));
                }

                Notification::make()
                    ->title($existing ? 'Договорённость обновлена' : 'Договорённость сохранена')
                    ->success()
                    ->send();
            })
            ->extraModalFooterActions(function (Model $r): array {
                $existing = $this->existingActivePromise($r);
                if (! $existing) {
                    return [];
                }

                return [
                    Tables\Actions\Action::make('fulfil')
                        ->label('Подтвердить оплату')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->modalHeading('Подтверждение оплаты по обещанию')
                        ->modalDescription('Будет создан Payment, покрывающий указанные блоки. Обещание закроется как выполненное.')
                        ->fillForm(function () use ($r, $existing): array {
                            $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number);
                            $info = app(DebtorsReport::class)->computeDebtAmount(User::find($r->id), (int) $r->course_id, $blocks);

                            return [
                                'amount' => $existing->amount !== null ? (float) $existing->amount : $info['amount'],
                                'start_block' => ! empty($blocks) ? min($blocks) : (int) $r->ref_block_number,
                                'end_block' => ! empty($blocks) ? max($blocks) : (int) $r->ref_block_number,
                                'transaction_id' => 'promise_#'.$existing->id,
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
                        ->action(function (array $data) use ($existing): void {
                            app(PromiseFulfillment::class)->fulfil($existing, $data, (bool) ($data['silent'] ?? false));
                            Notification::make()
                                ->title('Платёж создан, обещание закрыто')
                                ->success()
                                ->send();
                        })
                        ->cancelParentActions(),
                    Tables\Actions\Action::make('cancel_promise')
                        ->label('Отменить договорённость')
                        ->color('danger')
                        ->icon('heroicon-o-x-circle')
                        ->action(function () use ($existing): void {
                            $existing->update([
                                'status' => PaymentPromise::STATUS_CANCELLED,
                                'cancelled_at' => now(),
                            ]);
                            Notification::make()->title('Договорённость отменена')->warning()->send();
                        })
                        ->cancelParentActions(),
                ];
            });
    }

    private function installmentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('installment')
            ->label('Рассрочка')
            ->icon('heroicon-o-banknotes')
            ->color('info')
            ->modalHeading(fn (Model $r): string => 'Рассрочка по оплате — '.($r->name ?: $r->email))
            ->modalDescription('Каждая строка — отдельное обещание оплаты. Все строки объединяются в один план рассрочки.')
            ->modalWidth('2xl')
            ->fillForm(function (Model $r): array {
                $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number);
                $info = app(DebtorsReport::class)->computeDebtAmount(User::find($r->id), (int) $r->course_id, $blocks);
                $totalDebt = $info['amount'] !== null ? (float) $info['amount'] : null;

                // 3 равных взноса по умолчанию, остаток в последний
                $schedule = [];
                $start = now()->addDays(7);
                if ($totalDebt !== null && $totalDebt > 0) {
                    $base = (int) floor(($totalDebt * 100) / 3) / 100; // в копейках, чтоб не плыло
                    $remainder = $totalDebt - $base * 2;
                    $amounts = [$base, $base, $remainder];
                    for ($i = 0; $i < 3; $i++) {
                        $schedule[] = [
                            'promised_at' => $start->copy()->addMonths($i)->toDateString(),
                            'amount' => $amounts[$i],
                        ];
                    }
                } else {
                    for ($i = 0; $i < 3; $i++) {
                        $schedule[] = [
                            'promised_at' => $start->copy()->addMonths($i)->toDateString(),
                            'amount' => null,
                        ];
                    }
                }

                return ['schedule' => $schedule];
            })
            ->form([
                Forms\Components\Repeater::make('schedule')
                    ->label('График платежей')
                    ->schema([
                        Forms\Components\DatePicker::make('promised_at')
                            ->label('Дата')
                            ->required()
                            ->native(false)
                            ->minDate(now()->subDays(1)),
                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма (₽)')
                            ->numeric()
                            ->required()
                            ->minValue(1),
                    ])
                    ->columns(2)
                    ->minItems(2)
                    ->defaultItems(3)
                    ->addActionLabel('Добавить платёж')
                    ->reorderable(false)
                    ->required(),
                Forms\Components\Textarea::make('note')
                    ->label('Комментарий ко всему плану')
                    ->rows(3)
                    ->placeholder('Например: «договорились на 3 месяца, по 5 числам»'),
            ])
            ->action(function (Model $r, array $data): void {
                $user = User::find($r->id);
                $course = Course::find($r->course_id);
                if (! $user || ! $course) {
                    Notification::make()->title('Студент или курс не найдены')->danger()->send();

                    return;
                }

                app(InstallmentPlanCreator::class)->create(
                    $user,
                    $course,
                    array_values($data['schedule'] ?? []),
                    isset($data['note']) && trim((string) $data['note']) !== '' ? $data['note'] : null,
                );

                Notification::make()
                    ->title('Рассрочка создана')
                    ->body('Создано платежей: '.count($data['schedule'] ?? []))
                    ->success()
                    ->send();
            });
    }

    private function existingActivePromise(Model $r): ?PaymentPromise
    {
        return PaymentPromise::query()
            ->forPair((int) $r->id, (int) $r->course_id)
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->orderByDesc('promised_at')
            ->first();
    }

    private function sendReminderBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('send_reminder')
            ->label('Напомнить в TG/VK')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalHeading('Рассылка напоминания должникам')
            ->modalDescription('Доступные плейсхолдеры: {name}, {course}, {block}. Сообщение отправится в Telegram и/или VK — куда у студента привязан аккаунт.')
            ->form([
                Forms\Components\Textarea::make('text')
                    ->label('Текст напоминания')
                    ->rows(7)
                    ->required()
                    ->default("Намасте, {name}!\n\nНапоминаем: блок №{block} курса «{course}» уже идёт, а оплата ещё не поступила. Чтобы не потерять доступ к материалам, оформите блок в личном кабинете.\n\nПерейти к оплате: ".url('/login')),
                Forms\Components\Toggle::make('to_telegram')
                    ->label('Telegram')
                    ->default(true),
                Forms\Components\Toggle::make('to_vk')
                    ->label('VK')
                    ->default(true),
            ])
            ->action(function (Collection $records, array $data) {
                $report = app(DebtorsReport::class);
                $courseTitles = $report->courseTitles();
                $toTelegram = (bool) ($data['to_telegram'] ?? true);
                $toVk = (bool) ($data['to_vk'] ?? true);
                $template = (string) $data['text'];

                $sent = 0;
                $noChannel = 0;

                foreach ($records as $record) {
                    /** @var User $record */
                    $hasTg = $toTelegram && ! empty($record->telegram_id);
                    $hasVk = $toVk && ! empty($record->vk_id);
                    if (! $hasTg && ! $hasVk) {
                        $noChannel++;

                        continue;
                    }

                    $rendered = strtr($template, [
                        '{name}' => $record->name ?: 'Друг',
                        '{course}' => $courseTitles[$record->course_id] ?? '',
                        '{block}' => (string) $record->ref_block_number,
                    ]);

                    SendMessengerAlerts::dispatch($record, $rendered, $hasTg, $hasVk);
                    $sent++;
                }

                Notification::make()
                    ->title('Очередь поставлена')
                    ->body("Отправлено в очередь: {$sent}. Без TG/VK: {$noChannel}.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
