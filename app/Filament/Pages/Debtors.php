<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\DebtorsExporter;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\DebtorsTotalWidget;
use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
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
            ->defaultPaginationPageOption(25)
            ->striped()
            ->groups([
                Tables\Grouping\Group::make('course_id')
                    ->label('Курс')
                    ->getTitleFromRecordUsing(fn (Model $r): string => $courseTitles[$r->course_id] ?? '—')
                    ->collapsible(),
            ])
            ->defaultGroup('course_id')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Студент')
                    ->description(function (Model $r): string {
                        $bits = [];
                        if ((bool) $r->is_unreliable) {
                            $bits[] = '🚩';
                        }
                        if (! empty($r->telegram_id)) {
                            $bits[] = 'TG';
                        }
                        if (! empty($r->vk_id)) {
                            $bits[] = 'VK';
                        }
                        if (! empty($r->max_user_id)) {
                            $bits[] = 'Max';
                        }
                        if (! empty($r->phone)) {
                            $bits[] = '📞';
                        }
                        if (! empty($r->instagram)) {
                            $bits[] = 'IG';
                        }
                        if (! empty($r->facebook)) {
                            $bits[] = 'FB';
                        }
                        $bits[] = '#'.$r->id;
                        if (! empty($r->last_activity_at)) {
                            $dt = $r->last_activity_at instanceof \Carbon\CarbonInterface
                                ? $r->last_activity_at
                                : \Illuminate\Support\Carbon::parse($r->last_activity_at);
                            $bits[] = 'был '.$dt->diffForHumans();
                        }

                        return implode(' · ', $bits);
                    })
                    ->weight('medium')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Small)
                    ->color('primary')
                    ->wrap()
                    ->width('33%')
                    ->tooltip(fn (Model $r): ?string => $r->is_unreliable && ! empty($r->unreliable_reason)
                        ? '🚩 Неблагонадёжный: '.$r->unreliable_reason
                        : null)
                    ->searchable(['name', 'email', 'phone', 'max_user_id', 'instagram', 'facebook'])
                    ->action($this->viewUserCardAction()),

                Tables\Columns\TextColumn::make('ref_block_number')
                    ->label('Блок')
                    ->formatStateUsing(fn ($state): string => '№'.$state)
                    ->description(function (Model $r) use ($blocksLookup, $report): ?string {
                        $block = $blocksLookup[$r->course_id.':'.$r->ref_block_number] ?? null;
                        $lines = [];
                        if ($block instanceof CourseBlock && $block->starts_at && $block->ends_at) {
                            $lines[] = $block->starts_at->format('d.m').' – '.$block->ends_at->format('d.m.Y');
                        }
                        $overdue = $report->daysOverdueFor((int) $r->course_id, (int) $r->ref_block_number);
                        if ($overdue > 0) {
                            $lines[] = DebtorsReport::formatOverdue($overdue);
                        }

                        return empty($lines) ? null : implode(' · ', $lines);
                    })
                    ->sortable()
                    ->alignCenter()
                    ->width('17%'),

                Tables\Columns\TextColumn::make('course_user_status')
                    ->label('Статус обучения')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'Записался', 'Вернулся', 'Выпускник' => 'success',
                        'Рассрочка', 'Приостановка', 'Льготник' => 'warning',
                        'Покинул', 'Исключен' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Model $r): ?string => $r->course_user_left_after_block
                        ? 'после блока №'.((int) $r->course_user_left_after_block)
                        : null)
                    ->toggleable()
                    ->width('14%'),

                Tables\Columns\TextColumn::make('debt_amount')
                    ->label('Долг')
                    ->alignRight()
                    ->weight('bold')
                    ->wrap()
                    ->width('25%')
                    ->getStateUsing(function (Model $r) use ($report): string {
                        $info = self::debtAmountInfo($r, $report);
                        if ($info['amount'] === null) {
                            return '—';
                        }
                        $formatted = number_format($info['amount'], 0, '.', ' ').' ₽';

                        return $info['missing'] > 0 ? '≈ '.$formatted : $formatted;
                    })
                    ->description(function (Model $r): string {
                        $type = match ($r->debt_type) {
                            'not_renewed' => 'Не продлил',
                            'no_payment' => 'Без оплат',
                            default => '—',
                        };
                        $blocks = self::debtBlocksFormatted(
                            (int) $r->id,
                            (int) $r->course_id,
                            (int) $r->ref_block_number,
                        );

                        return $type.' · '.$blocks;
                    })
                    ->color(function (Model $r) use ($report): string {
                        $info = self::debtAmountInfo($r, $report);

                        return ($info['amount'] ?? 0) >= 10000 ? 'danger' : 'warning';
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
                    }),

                Tables\Columns\TextColumn::make('promise')
                    ->label('Обещание')
                    ->badge()
                    ->width('17%')
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
                    ->toggleable(isToggledHiddenByDefault: true)
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

                Tables\Filters\Filter::make('has_max')
                    ->label('Есть Max')
                    ->query(fn ($query) => $query->whereNotNull('users.max_user_id')),

                Tables\Filters\Filter::make('has_phone')
                    ->label('Есть телефон')
                    ->query(fn ($query) => $query->whereNotNull('users.phone')),

                Tables\Filters\Filter::make('has_email')
                    ->label('Есть email')
                    ->query(fn ($query) => $query->whereNotNull('users.email')),

                Tables\Filters\Filter::make('has_instagram')
                    ->label('Есть Instagram')
                    ->query(fn ($query) => $query->whereNotNull('users.instagram')),

                Tables\Filters\Filter::make('has_facebook')
                    ->label('Есть Facebook')
                    ->query(fn ($query) => $query->whereNotNull('users.facebook')),

                Tables\Filters\SelectFilter::make('course_user_status')
                    ->label('Статус обучения')
                    ->multiple()
                    ->options([
                        'Записался' => 'Записался',
                        'Рассрочка' => 'Рассрочка',
                        'Приостановка' => 'Приостановка',
                        'Вернулся' => 'Вернулся',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['values'])) {
                            $query->whereIn('d.course_user_status', $data['values']);
                        }
                    }),

                Tables\Filters\TernaryFilter::make('is_unreliable')
                    ->label('Неблагонадёжные')
                    ->placeholder('Все')
                    ->trueLabel('Только 🚩')
                    ->falseLabel('Скрыть 🚩')
                    ->queries(
                        true: fn ($query) => $query->where('users.is_unreliable', true),
                        false: fn ($query) => $query->where('users.is_unreliable', false),
                        blank: fn ($query) => $query,
                    ),

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
                Tables\Actions\ActionGroup::make([
                    $this->quickConfirmAction(),
                    $this->quickReminderAction(),
                    $this->promiseAction(),
                    $this->installmentAction(),
                    $this->markUnreliableAction(),
                    $this->clearUnreliableAction(),
                    $this->openCardAction(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    $this->sendReminderBulkAction(),
                    Tables\Actions\ExportBulkAction::make()
                        ->exporter(DebtorsExporter::class)
                        ->label('Экспорт'),
                ]),
            ])
            ->defaultSort('users.name', 'asc')
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
            ->where('is_conditional', false)
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
            ->visible(fn (Model $r): bool => ! (bool) $r->is_unreliable)
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
                Forms\Components\Toggle::make('grant_access')
                    ->label('Открыть доступ под обещание')
                    ->helperText('Студент получит доступ к материалам авансом, до фактической оплаты. Отзыв доступа — вручную.')
                    ->live()
                    ->dehydrated(true),
                Forms\Components\Radio::make('access_mode')
                    ->label('Что открыть')
                    ->options([
                        ConditionalAccessGranter::MODE_FULL => 'Весь курс',
                        ConditionalAccessGranter::MODE_BLOCKS => 'Конкретные блоки',
                    ])
                    ->default(ConditionalAccessGranter::MODE_BLOCKS)
                    ->inline()
                    ->live()
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('grant_access')),
                Forms\Components\TextInput::make('access_blocks')
                    ->label('Номера блоков (через запятую)')
                    ->placeholder('5, 6, 7')
                    ->helperText('Перечислите номера блоков курса, которые нужно открыть.')
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('grant_access')
                        && $get('access_mode') === ConditionalAccessGranter::MODE_BLOCKS),
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
                    $promise = $existing;
                } else {
                    $promise = PaymentPromise::create(array_merge($payload, [
                        'user_id' => $r->id,
                        'course_id' => $r->course_id,
                        'status' => PaymentPromise::STATUS_ACTIVE,
                    ]));
                }

                $grantOpened = false;
                if (! empty($data['grant_access'])) {
                    $mode = (string) ($data['access_mode'] ?? ConditionalAccessGranter::MODE_BLOCKS);
                    $blocks = $mode === ConditionalAccessGranter::MODE_BLOCKS
                        ? $this->parseBlockNumbers((string) ($data['access_blocks'] ?? ''))
                        : [];
                    app(ConditionalAccessGranter::class)->grantForPromise($promise, $mode, $blocks);
                    $grantOpened = true;
                }

                Notification::make()
                    ->title($existing ? 'Договорённость обновлена' : 'Договорённость сохранена')
                    ->body($grantOpened ? 'Доступ под обещание открыт, студент получил уведомление в TG.' : null)
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
                    Tables\Actions\Action::make('grant_access')
                        ->label('Открыть доступ')
                        ->color('info')
                        ->icon('heroicon-o-lock-open')
                        ->visible(fn () => ! app(ConditionalAccessGranter::class)->hasActiveGrant($existing))
                        ->modalHeading('Открыть доступ под обещание')
                        ->fillForm([
                            'access_mode' => ConditionalAccessGranter::MODE_BLOCKS,
                            'access_blocks' => '',
                        ])
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
                                ->label('Номера блоков (через запятую)')
                                ->placeholder('5, 6, 7')
                                ->visible(fn (Forms\Get $get): bool => $get('access_mode') === ConditionalAccessGranter::MODE_BLOCKS),
                        ])
                        ->action(function (array $data) use ($existing): void {
                            $mode = (string) ($data['access_mode'] ?? ConditionalAccessGranter::MODE_BLOCKS);
                            $blocks = $mode === ConditionalAccessGranter::MODE_BLOCKS
                                ? $this->parseBlockNumbers((string) ($data['access_blocks'] ?? ''))
                                : [];
                            app(ConditionalAccessGranter::class)->grantForPromise($existing, $mode, $blocks);
                            Notification::make()->title('Доступ открыт')->success()->send();
                        })
                        ->cancelParentActions(),
                    Tables\Actions\Action::make('revoke_access')
                        ->label('Отозвать доступ')
                        ->color('danger')
                        ->icon('heroicon-o-lock-closed')
                        ->visible(fn () => app(ConditionalAccessGranter::class)->hasActiveGrant($existing))
                        ->requiresConfirmation()
                        ->modalDescription('Все conditional-платежи по этому обещанию будут удалены. Студент потеряет доступ к открытым под обещание блокам.')
                        ->action(function () use ($existing): void {
                            $n = app(ConditionalAccessGranter::class)->revokeForPromise($existing);
                            Notification::make()
                                ->title('Доступ отозван')
                                ->body("Удалено платежей: {$n}")
                                ->warning()
                                ->send();
                        })
                        ->cancelParentActions(),
                ];
            });
    }

    /**
     * Парсит строку «5, 6, 7» / «1-3, 5» в массив номеров блоков.
     *
     * @return list<int>
     */
    private function parseBlockNumbers(string $input): array
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

    private function installmentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('installment')
            ->label('Рассрочка')
            ->icon('heroicon-o-banknotes')
            ->color('info')
            ->visible(fn (Model $r): bool => ! (bool) $r->is_unreliable)
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
                        Forms\Components\TextInput::make('block_from')
                            ->label('Откр. с блока №')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Заполните оба поля, чтобы открыть доступ к диапазону при сохранении плана.'),
                        Forms\Components\TextInput::make('block_to')
                            ->label('по №')
                            ->numeric()
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

                $schedule = array_values($data['schedule'] ?? []);
                $result = app(InstallmentPlanCreator::class)->create(
                    $user,
                    $course,
                    array_map(fn ($row) => [
                        'promised_at' => $row['promised_at'],
                        'amount' => $row['amount'],
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
            });
    }

    private function quickConfirmAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('quick_confirm')
            ->label('Подтвердить оплату')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Model $r): bool => $this->existingActivePromise($r) !== null)
            ->modalHeading(fn (Model $r): string => 'Подтверждение оплаты — '.($r->name ?: $r->email))
            ->modalDescription('Будет создан Payment, покрывающий указанные блоки. Обещание закроется как выполненное.')
            ->fillForm(function (Model $r): array {
                $existing = $this->existingActivePromise($r);
                $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number);
                $info = app(DebtorsReport::class)->computeDebtAmount(User::find($r->id), (int) $r->course_id, $blocks);

                return [
                    'amount' => $existing?->amount !== null ? (float) $existing->amount : $info['amount'],
                    'start_block' => ! empty($blocks) ? min($blocks) : (int) $r->ref_block_number,
                    'end_block' => ! empty($blocks) ? max($blocks) : (int) $r->ref_block_number,
                    'transaction_id' => $existing ? 'promise_#'.$existing->id : '',
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
                    ->helperText('Включите, если фиксируете факт оплаты задним числом.'),
            ])
            ->action(function (Model $r, array $data): void {
                $existing = $this->existingActivePromise($r);
                if (! $existing) {
                    Notification::make()->title('Активного обещания нет')->danger()->send();

                    return;
                }
                app(PromiseFulfillment::class)->fulfil($existing, $data, (bool) ($data['silent'] ?? false));
                Notification::make()->title('Платёж создан, обещание закрыто')->success()->send();
            });
    }

    private function quickReminderAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('quick_reminder')
            ->label('Напомнить в TG/VK')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Model $r): bool => ! empty($r->telegram_id) || ! empty($r->vk_id))
            ->modalHeading(fn (Model $r): string => 'Напоминание — '.($r->name ?: $r->email))
            ->fillForm(fn (Model $r): array => [
                'to_telegram' => ! empty($r->telegram_id),
                'to_vk' => ! empty($r->vk_id),
                'text' => "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идёт, а оплата ещё не поступила. Чтобы не потерять доступ к материалам, оформите блок в личном кабинете.\n\nПерейти к оплате: ".url('/login'),
            ])
            ->form([
                Forms\Components\Textarea::make('text')
                    ->label('Текст напоминания')->rows(7)->required()
                    ->helperText('Плейсхолдеры: {name}, {course}, {block}.'),
                Forms\Components\Toggle::make('to_telegram')->label('Telegram'),
                Forms\Components\Toggle::make('to_vk')->label('VK'),
            ])
            ->action(function (Model $r, array $data): void {
                $titles = app(DebtorsReport::class)->courseTitles();
                $user = User::find($r->id);
                if (! $user) {
                    Notification::make()->title('Студент не найден')->danger()->send();

                    return;
                }
                $hasTg = (bool) ($data['to_telegram'] ?? false) && ! empty($user->telegram_id);
                $hasVk = (bool) ($data['to_vk'] ?? false) && ! empty($user->vk_id);
                if (! $hasTg && ! $hasVk) {
                    Notification::make()->title('Нет каналов для отправки')->danger()->send();

                    return;
                }
                $rendered = strtr((string) $data['text'], [
                    '{name}' => $user->name ?: 'Друг',
                    '{course}' => $titles[$r->course_id] ?? '',
                    '{block}' => (string) $r->ref_block_number,
                ]);
                SendMessengerAlerts::dispatch($user, $rendered, $hasTg, $hasVk);
                Notification::make()->title('Поставлено в очередь')->success()->send();
            });
    }

    private function openCardAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('open_card')
            ->label('Карточка студента')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Model $r): string => UserResource::getUrl('edit', ['record' => $r->id]))
            ->openUrlInNewTab();
    }

    private function markUnreliableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('mark_unreliable')
            ->label('🚩 Отметить как неблагонадёжного')
            ->icon('heroicon-o-flag')
            ->color('danger')
            ->visible(fn (Model $r): bool => ! (bool) $r->is_unreliable)
            ->modalHeading(fn (Model $r): string => 'Неблагонадёжный — '.($r->name ?: $r->email))
            ->modalDescription('Студенту перестанут действовать скидки лояльности, action’ы «Обещание» и «Рассрочка» исчезнут, conditional-доступ будет недоступен. Флаг автоматически не снимается.')
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Причина (обязательно)')
                    ->rows(3)
                    ->required()
                    ->placeholder('Например: «3 раза подряд срывал обещания оплаты без предупреждения».'),
            ])
            ->action(function (Model $r, array $data): void {
                $user = User::find($r->id);
                if (! $user) {
                    Notification::make()->title('Студент не найден')->danger()->send();

                    return;
                }
                $user->markUnreliable((string) $data['reason'], auth()->user(), auto: false);
                Notification::make()
                    ->title('Флаг выставлен')
                    ->body('Привилегии loyalty, обещания и conditional-доступ отключены.')
                    ->warning()
                    ->send();
            });
    }

    private function clearUnreliableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('clear_unreliable')
            ->label('Снять флаг неблагонадёжности')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (Model $r): bool => (bool) $r->is_unreliable)
            ->requiresConfirmation()
            ->modalDescription(fn (Model $r): string => trim(
                'Восстановит loyalty-скидку и доступ к обещаниям/рассрочке. '
                .($r->unreliable_reason ? 'Текущая причина: «'.$r->unreliable_reason.'».' : '')
                .($r->discipline_improved_since
                    ? ' Поведение улучшилось с '.$r->discipline_improved_since->format('m.Y').'.'
                    : '')
            ))
            ->action(function (Model $r): void {
                $user = User::find($r->id);
                if (! $user) {
                    Notification::make()->title('Студент не найден')->danger()->send();

                    return;
                }
                $user->clearUnreliable(auth()->user());
                Notification::make()->title('Привилегии восстановлены')->success()->send();
            });
    }

    protected function getHeaderWidgets(): array
    {
        return [DebtorsTotalWidget::class];
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
