<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\DebtorsExporter;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\DebtorsTotalWidget;
use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MarketingSetting;
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
use Illuminate\Support\Facades\Mail;

class Debtors extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Выбранные годы «линзы»: долги определяются только за эти годы (год = год
     * даты блока). Пусто = все годы. Запоминается ГЛОБАЛЬНО в
     * MarketingSetting.debtors_notify_years — восстанавливается при возврате на
     * страницу (mount) и пишется при каждом переключении (toggleYear). Та же
     * настройка позже питает авто-рассылку должникам за выбранный год.
     *
     * @var list<int>
     */
    public array $selectedYears = [];

    public function mount(): void
    {
        $this->selectedYears = self::normalizeYears(
            MarketingSetting::cached()?->debtors_notify_years ?? []
        );
    }

    /** @var array<int, Payment|null> */
    private static array $paymentCache = [];

    /** @var array<string, list<int>>  ключ "{course_id}:{ref}:{yearSig}" */
    private static array $courseBlockNumbersCache = [];

    /** @var array<int, array<int, int>>  course_id => [block_number => year] (по starts_at) */
    private static array $blockYearsCache = [];

    /** @var array<string, \Illuminate\Database\Eloquent\Collection<int, Payment>>  ключ "{user_id}:{course_id}" */
    private static array $userCoursePaymentsCache = [];

    /** @var array<string, array{amount:?float, missing:int}>  ключ "{user_id}:{course_id}:{yearSig}" */
    private static array $debtAmountCache = [];

    /** @var array<string, ?int>  "{user_id}:{course_id}" => явный joined_at_block из course_user */
    private static array $joinedAtBlockCache = [];

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

    /**
     * Доступные годы для переключателей (по убыванию). Отдаётся в blade.
     *
     * @return list<int>
     */
    public function getYearOptions(): array
    {
        return app(DebtorsReport::class)->availableYears();
    }

    /**
     * Переключить год в выборке линзы. Пустая выборка = все годы.
     */
    public function toggleYear(int $year): void
    {
        if (in_array($year, $this->selectedYears, true)) {
            $this->selectedYears = array_values(array_filter(
                $this->selectedYears,
                static fn (int $y) => $y !== $year,
            ));
        } else {
            $this->selectedYears[] = $year;
            sort($this->selectedYears);
        }

        // Глобально запоминаем выбор: страница восстановит его при возврате, а
        // будущая авто-рассылка возьмёт этот же год. saved() в MarketingSetting
        // сбрасывает кэш singleton'а.
        $setting = MarketingSetting::first() ?? new MarketingSetting;
        $setting->debtors_notify_years = $this->selectedYears;
        $setting->save();

        // Год влияет на сам query таблицы, но это не «родная» табличная сила
        // (поиск/фильтр/сортировка/страница), поэтому Filament не сбрасывает
        // кэш строк сам — таблица показывала бы прошлую выборку до перещёлка
        // пагинации. Сбрасываем на 1-ю страницу и чистим кэш строк вручную.
        // Плюс сбрасываем мемо отчёта: Filament на boot (ещё со старым
        // selectedYears) уже мог построить report()/blocksLookup() для bulk-
        // состояния — без сброса повторный query взял бы прошлогоднюю выборку.
        $this->reportMemo = null;
        $this->blocksLookupMemo = null;
        $this->resetPage();
        $this->flushCachedTableRecords();

        // Реактивно обновляем тотал-виджет в шапке (он — отдельный Livewire-компонент).
        $this->dispatch('debtors-years-updated', years: $this->selectedYears);
    }

    /**
     * Нормализованная сигнатура годов для ключей кэшей (пусто = «all»).
     *
     * @param  array<int|string>|null  $years
     */
    private static function yearSignature(?array $years): string
    {
        $norm = self::normalizeYears($years);

        return empty($norm) ? 'all' : implode('-', $norm);
    }

    /**
     * @param  array<int|string>|null  $years
     * @return list<int>
     */
    private static function normalizeYears(?array $years): array
    {
        if (empty($years)) {
            return [];
        }

        $norm = array_values(array_unique(array_filter(
            array_map(static fn ($y) => (int) $y, $years),
            static fn (int $y) => $y > 0,
        )));
        sort($norm);

        return $norm;
    }

    /**
     * Год-aware отчёт под ТЕКУЩУЮ выборку годов. Важно: строится лениво (на
     * рендере, уже после экшена toggleYear), а не на boot таблицы — иначе query
     * собирался бы со старым значением selectedYears и список не обновлялся бы
     * до перещёлка пагинации. Мемоизация в рамках одного запроса безопасна:
     * selectedYears после экшена не меняется.
     */
    private ?DebtorsReport $reportMemo = null;

    /** @var array<string, CourseBlock>|null */
    private ?array $blocksLookupMemo = null;

    private function report(): DebtorsReport
    {
        return $this->reportMemo ??= app(DebtorsReport::class)->forYears($this->selectedYears);
    }

    /**
     * @return array<string, CourseBlock>
     */
    private function blocksLookup(): array
    {
        return $this->blocksLookupMemo ??= $this->report()->blocksLookup();
    }

    public function table(Table $table): Table
    {
        // Внимание: НЕ вызывать здесь report()/blocksLookup() — table() исполняется
        // на boot, до экшена toggleYear. Год-зависимое читаем лениво в замыканиях
        // через $this->report() / $this->selectedYears. courseTitles для опций
        // фильтра и подписей групп берём по «всем годам» (надмножество, стабильно).
        $courseTitles = app(DebtorsReport::class)->courseTitles();

        return $table
            ->query(fn () => $this->report()->query())
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
                    ->description(function (Model $r): ?string {
                        $block = $this->blocksLookup()[$r->course_id.':'.$r->ref_block_number] ?? null;
                        $lines = [];
                        if ($block instanceof CourseBlock && $block->starts_at && $block->ends_at) {
                            $lines[] = $block->starts_at->format('d.m').' – '.$block->ends_at->format('d.m.Y');
                        }
                        $overdue = $this->report()->daysOverdueFor((int) $r->course_id, (int) $r->ref_block_number);
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
                    ->getStateUsing(function (Model $r): string {
                        $info = self::debtAmountInfo($r, $this->report(), $this->selectedYears);
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
                            $this->selectedYears,
                        );

                        return $type.' · '.$blocks;
                    })
                    ->color(function (Model $r): string {
                        $info = self::debtAmountInfo($r, $this->report(), $this->selectedYears);

                        return ($info['amount'] ?? 0) >= 10000 ? 'danger' : 'warning';
                    })
                    ->tooltip(function (Model $r): ?string {
                        $info = self::debtAmountInfo($r, $this->report(), $this->selectedYears);
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
                    ->getStateUsing(function (Model $r): ?string {
                        $promise = $this->report()->promiseFor((int) $r->id, (int) $r->course_id);
                        if (! $promise) {
                            return null;
                        }
                        $date = $promise->promised_at?->format('d.m.Y') ?? '—';

                        return $promise->isOverdue() ? "просрочено {$date}" : "до {$date}";
                    })
                    ->color(function (Model $r): string {
                        $promise = $this->report()->promiseFor((int) $r->id, (int) $r->course_id);
                        if (! $promise) {
                            return 'gray';
                        }

                        return $promise->isOverdue() ? 'danger' : 'warning';
                    })
                    ->tooltip(function (Model $r): ?string {
                        $promise = $this->report()->promiseFor((int) $r->id, (int) $r->course_id);
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
                        ->options(fn (): array => ['years' => $this->selectedYears])
                        ->label('Экспорт'),
                ]),
            ])
            ->defaultSort('users.name', 'asc')
            ->emptyStateHeading('Должников не найдено')
            ->emptyStateDescription('Либо нет активных курсов с reference-блоком, либо все студенты платежами покрыты.');
    }

    /**
     * @param  array<int|string>  $years
     * @return array{amount:?float, missing:int}
     */
    private static function debtAmountInfo(Model $r, DebtorsReport $report, array $years = []): array
    {
        $key = $r->id.':'.$r->course_id.':'.self::yearSignature($years);
        if (isset(self::$debtAmountCache[$key])) {
            return self::$debtAmountCache[$key];
        }

        $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number, $years);
        $info = $report->computeDebtAmount(User::find($r->id), (int) $r->course_id, $blocks);

        return self::$debtAmountCache[$key] = [
            'amount' => $info['amount'],
            'missing' => $info['missing_tariffs'],
        ];
    }

    /**
     * @param  array<int|string>  $years
     */
    private static function debtBlocksFormatted(int $userId, int $courseId, int $refNumber, array $years = []): string
    {
        $numbers = self::debtBlocks($userId, $courseId, $refNumber, $years);
        if (empty($numbers)) {
            return '—';
        }

        return DebtorsReport::formatBlockRanges($numbers);
    }

    /**
     * Неоплаченные блоки пары (user, course) ≤ reference, ≥ floor входа.
     * При активной год-линзе ($years непусто) дополнительно ограничивается
     * блоками, чья дата (starts_at) попадает в выбранные годы.
     *
     * @param  array<int|string>|null  $years
     * @return list<int>
     */
    public static function debtBlocks(int $userId, int $courseId, int $refNumber, ?array $years = null): array
    {
        $years = self::normalizeYears($years);
        $yearSig = self::yearSignature($years);

        $blockKey = $courseId.':'.$refNumber.':'.$yearSig;
        $allBlocks = self::$courseBlockNumbersCache[$blockKey] ??= self::candidateBlockNumbers($courseId, $refNumber, $years);

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

        // Нижняя граница долга: студент мог присоединиться к потоку в середине —
        // блоки до его «блока входа» (явный joined_at_block либо первый
        // оплаченный) в долг не начисляются.
        // array_key_exists, а не `??=`: легитимное значение кэша — null (у пары
        // нет joined_at_block). `??=` считал бы null «непрогретым» и бил бы
        // запрос на каждую такую пару заново (N+1 на сводке должников).
        if (! array_key_exists($payKey, self::$joinedAtBlockCache)) {
            $v = DB::table('course_user')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->value('joined_at_block');
            self::$joinedAtBlockCache[$payKey] = $v !== null ? (int) $v : null;
        }
        $explicitJoined = self::$joinedAtBlockCache[$payKey];
        $floor = DebtorsReport::debtFloor($explicitJoined, $payments);

        $debt = [];
        foreach ($allBlocks as $n) {
            if ($floor !== null && $n < $floor) {
                continue;
            }
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

    /**
     * Батч-прогрев кэшей платежей и joined_at_block сразу по всем парам
     * (user, course). Без него debtBlocks() лениво бьёт 2 запроса на КАЖДУЮ
     * уникальную пару — на сводке по всем должникам это сотни запросов
     * (≈2 × число пар). Вызывается из DebtorsReport::totalDebtForQuery перед
     * циклом: два whereIn-запроса вместо N+1.
     *
     * @param  iterable<object{id:int|string, course_id:int|string}>  $rows
     */
    public static function preloadPairCaches(iterable $rows): void
    {
        $userIds = [];
        $courseIds = [];
        $pairs = [];
        foreach ($rows as $r) {
            $uid = (int) $r->id;
            $cid = (int) $r->course_id;
            $userIds[$uid] = true;
            $courseIds[$cid] = true;
            $pairs[$uid.':'.$cid] = true;
        }
        if (empty($pairs)) {
            return;
        }

        $userIds = array_keys($userIds);
        $courseIds = array_keys($courseIds);

        // Только пары, ещё не лежащие в кэше (debtBlocks мог прогреть часть при
        // рендере строк таблицы) — иначе бы перетёрли уже посчитанное.
        $missing = array_filter(
            array_keys($pairs),
            fn (string $k) => ! array_key_exists($k, self::$userCoursePaymentsCache),
        );
        if (empty($missing)) {
            return;
        }

        // 1) Платежи всех нужных пар одним запросом.
        $payments = Payment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->whereIn('status', ['paid', 'success'])
            ->where('is_conditional', false)
            ->get(['user_id', 'course_id', 'start_block', 'end_block'])
            ->groupBy(fn (Payment $p) => ((int) $p->user_id).':'.((int) $p->course_id));

        // 2) joined_at_block всех нужных пар одним запросом.
        $joined = DB::table('course_user')
            ->whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('joined_at_block')
            ->get(['user_id', 'course_id', 'joined_at_block'])
            ->keyBy(fn ($r) => ((int) $r->user_id).':'.((int) $r->course_id));

        // Заполняем кэш для каждой запрошенной пары, включая пары без платежей /
        // без joined_at_block (пустая коллекция / null), чтобы debtBlocks по
        // ним не сходил повторно в БД через `??=`.
        foreach ($missing as $key) {
            self::$userCoursePaymentsCache[$key] = $payments->get($key, new Collection);
            self::$joinedAtBlockCache[$key] = isset($joined[$key])
                ? (int) $joined[$key]->joined_at_block
                : null;
        }
    }

    /**
     * Кандидаты для подсчёта долга: номера блоков курса ≤ reference. При
     * активной год-линзе ($years непусто) оставляем только блоки, чья дата
     * (starts_at) попадает в выбранные годы (блоки без даты отбрасываются).
     *
     * @param  list<int>  $years
     * @return list<int>
     */
    private static function candidateBlockNumbers(int $courseId, int $refNumber, array $years): array
    {
        if (empty($years)) {
            return CourseBlock::query()
                ->where('course_id', $courseId)
                ->where('number', '<=', $refNumber)
                ->orderBy('number')
                ->pluck('number')
                ->map(fn ($n) => (int) $n)
                ->all();
        }

        $byYear = self::$blockYearsCache[$courseId] ??= CourseBlock::query()
            ->where('course_id', $courseId)
            ->whereNotNull('starts_at')
            ->orderBy('number')
            ->get(['number', 'starts_at'])
            ->mapWithKeys(fn (CourseBlock $b) => [(int) $b->number => (int) $b->starts_at->year])
            ->all();

        $result = [];
        foreach ($byYear as $number => $year) {
            if ($number <= $refNumber && in_array($year, $years, true)) {
                $result[] = $number;
            }
        }
        sort($result);

        return $result;
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
                            $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number, $this->selectedYears);
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
                $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number, $this->selectedYears);
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
                $blocks = self::debtBlocks((int) $r->id, (int) $r->course_id, (int) $r->ref_block_number, $this->selectedYears);
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
            ->label('Напомнить')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Model $r): bool => ! empty($r->telegram_id) || ! empty($r->vk_id) || ! empty($r->email))
            ->modalHeading(fn (Model $r): string => 'Напоминание — '.($r->name ?: $r->email))
            ->fillForm(fn (Model $r): array => [
                'to_telegram' => ! empty($r->telegram_id),
                'to_vk' => ! empty($r->vk_id),
                'to_email' => ! empty($r->email),
                'subject' => 'Напоминание об оплате — {course}',
                'text' => "Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идёт, а оплата ещё не поступила. Чтобы не потерять доступ к материалам, оформите блок.\n\nОплатить курс: {pay_link}\nЛичный кабинет: ".url('/login'),
            ])
            ->form([
                Forms\Components\Textarea::make('text')
                    ->label('Текст напоминания')->rows(8)->required()
                    ->helperText('Плейсхолдеры: {name}, {course}, {block}, {pay_link}.'),
                Forms\Components\TextInput::make('subject')
                    ->label('Тема письма')
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('to_email'))
                    ->helperText('Только для email. Плейсхолдеры тоже работают.'),
                Forms\Components\Toggle::make('to_telegram')->label('Telegram'),
                Forms\Components\Toggle::make('to_vk')->label('VK'),
                Forms\Components\Toggle::make('to_email')->label('Email')->live(),
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
                $hasEmail = (bool) ($data['to_email'] ?? false)
                    && filter_var($user->email, FILTER_VALIDATE_EMAIL);
                if (! $hasTg && ! $hasVk && ! $hasEmail) {
                    Notification::make()->title('Нет каналов для отправки')->danger()->send();

                    return;
                }

                $slug = Course::query()->whereKey($r->course_id)->value('slug');
                $replacements = [
                    '{name}' => $user->name ?: 'Друг',
                    '{course}' => $titles[$r->course_id] ?? '',
                    '{block}' => (string) $r->ref_block_number,
                    '{pay_link}' => $slug ? route('student.course', $slug) : url('/login'),
                ];
                $rendered = strtr((string) $data['text'], $replacements);

                if ($hasTg || $hasVk) {
                    SendMessengerAlerts::dispatch($user, $rendered, $hasTg, $hasVk);
                }
                if ($hasEmail) {
                    $subject = strtr((string) ($data['subject'] ?? 'Напоминание об оплате'), $replacements);
                    Mail::to($user->email)->queue(new DebtorReminderMail($subject, $rendered, $user->name));
                }

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

    /**
     * Прокидывает начальную год-линзу в header-виджет при монтаже (важно для
     * состояния, восстановленного из query-string). Дальнейшие переключения —
     * через событие debtors-years-updated в toggleYear().
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['years' => $this->selectedYears];
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
            ->label('Напомнить')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalHeading('Рассылка напоминания должникам')
            ->modalDescription('Плейсхолдеры: {name}, {course}, {block}, {pay_link}. Уйдёт в выбранные каналы — куда у студента есть аккаунт/почта.')
            ->form([
                Forms\Components\Textarea::make('text')
                    ->label('Текст напоминания')
                    ->rows(8)
                    ->required()
                    ->default("Намасте, {name}!\n\nНапоминаем: блок №{block} курса «{course}» уже идёт, а оплата ещё не поступила. Чтобы не потерять доступ к материалам, оформите блок.\n\nОплатить курс: {pay_link}\nЛичный кабинет: ".url('/login')),
                Forms\Components\TextInput::make('subject')
                    ->label('Тема письма')
                    ->default('Напоминание об оплате — {course}')
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('to_email'))
                    ->helperText('Только для email. Плейсхолдеры тоже работают.'),
                Forms\Components\Toggle::make('to_telegram')
                    ->label('Telegram')
                    ->default(true),
                Forms\Components\Toggle::make('to_vk')
                    ->label('VK')
                    ->default(true),
                Forms\Components\Toggle::make('to_email')
                    ->label('Email')
                    ->default(true)
                    ->live(),
            ])
            ->action(function (Collection $records, array $data) {
                $report = app(DebtorsReport::class);
                $courseTitles = $report->courseTitles();
                // Карта course_id → slug для прямой ссылки на курс {pay_link}.
                $courseSlugs = Course::query()
                    ->whereIn('id', $records->pluck('course_id')->unique()->all())
                    ->pluck('slug', 'id');
                $toTelegram = (bool) ($data['to_telegram'] ?? true);
                $toVk = (bool) ($data['to_vk'] ?? true);
                $toEmail = (bool) ($data['to_email'] ?? true);
                $template = (string) $data['text'];
                $subjectTemplate = (string) ($data['subject'] ?? 'Напоминание об оплате');

                $sentMsg = 0;   // TG/VK
                $sentEmail = 0;
                $noChannel = 0;

                foreach ($records as $record) {
                    /** @var User $record */
                    $hasTg = $toTelegram && ! empty($record->telegram_id);
                    $hasVk = $toVk && ! empty($record->vk_id);
                    $hasEmail = $toEmail && filter_var($record->email, FILTER_VALIDATE_EMAIL);
                    if (! $hasTg && ! $hasVk && ! $hasEmail) {
                        $noChannel++;

                        continue;
                    }

                    $slug = $courseSlugs[$record->course_id] ?? null;
                    $replacements = [
                        '{name}' => $record->name ?: 'Друг',
                        '{course}' => $courseTitles[$record->course_id] ?? '',
                        '{block}' => (string) $record->ref_block_number,
                        '{pay_link}' => $slug ? route('shop.course.show', $slug) : url('/login'),
                    ];
                    $rendered = strtr($template, $replacements);

                    if ($hasTg || $hasVk) {
                        SendMessengerAlerts::dispatch($record, $rendered, $hasTg, $hasVk);
                        $sentMsg++;
                    }
                    if ($hasEmail) {
                        $subject = strtr($subjectTemplate, $replacements);
                        Mail::to($record->email)->queue(new DebtorReminderMail($subject, $rendered, $record->name));
                        $sentEmail++;
                    }
                }

                Notification::make()
                    ->title('Очередь поставлена')
                    ->body("В очередь: TG/VK — {$sentMsg}, email — {$sentEmail}. Без каналов: {$noChannel}.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
