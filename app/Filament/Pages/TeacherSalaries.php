<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\TeacherSalariesExporter;
use App\Filament\Resources\TeacherPayoutResource;
use App\Filament\Resources\TeacherResource;
use App\Filament\Widgets\TeacherSalariesTotalWidget;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\SalaryClosedPeriod;
use App\Models\Teacher;
use App\Services\TeacherPayoutPoster;
use App\Services\TeacherSalaryService;
use App\Support\RoleGate;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class TeacherSalaries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationLabel = 'Зарплаты';

    protected static ?string $title = 'Зарплаты преподавателей';

    protected static ?string $slug = 'teacher-salaries';

    protected static string $view = 'filament.pages.teacher-salaries';

    /** @var array<string, array<int, array<string, mixed>>>  кэш сводки по периоду */
    private array $summaryCache = [];

    /** @var array<string, array<int, int>>  period => {teacher_id => 1} закрытых */
    private array $closedTeacherCache = [];

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    protected function getHeaderWidgets(): array
    {
        return [TeacherSalariesTotalWidget::class];
    }

    /**
     * Прокидываем выбранный период в header-виджет (он помечен #[Reactive] $period),
     * чтобы при смене фильтра «Период» виджет пересчитывался.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['period' => $this->resolvePeriod()];
    }

    protected function getHeaderActions(): array
    {
        return [$this->blockPayoutAction()];
    }

    /**
     * Калькулятор выплаты по блоку/группе:
     *   (база × коэф%) × процент_препода% + допзанятия × коэф%.
     * Преподаватель выбирается в форме; результат сохраняется как TeacherPayout.
     */
    private function blockPayoutAction(): Actions\Action
    {
        return Actions\Action::make('block_payout')
            ->label('Рассчитать выплату по блоку')
            ->icon('heroicon-o-calculator')
            ->color('primary')
            ->modalHeading('Расчёт выплаты по блоку')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Записать выплату')
            ->fillForm(fn (): array => ['coefficient' => 92, 'post_to_finance' => true])
            ->form([
                Forms\Components\Select::make('teacher_id')
                    ->label('Преподаватель')
                    ->options(fn () => Teacher::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('course_id', null)),

                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->options(fn (Forms\Get $get) => $get('teacher_id')
                        ? Course::query()->where('teacher_id', $get('teacher_id'))->orderBy('title')->pluck('title', 'id')
                        : [])
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set): void {
                        $course = $state ? Course::find($state) : null;
                        $set('teacher_percent', $course?->salary_value);
                        $set('block_number', null);
                        $set('group_id', null);
                        $this->refreshBaseRevenue($get, $set);
                    }),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Select::make('block_number')
                        ->label('Блок')
                        ->options(fn (Forms\Get $get) => $get('course_id')
                            ? CourseBlock::query()
                                ->where('course_id', $get('course_id'))
                                ->orderBy('number')
                                ->get()
                                ->mapWithKeys(fn (CourseBlock $b) => [
                                    $b->number => 'Блок '.$b->number.($b->title ? ' · '.$b->title : ''),
                                ])
                            : [])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => $this->refreshBaseRevenue($get, $set)),

                    Forms\Components\Select::make('group_id')
                        ->label('Группа')
                        ->options(fn (Forms\Get $get) => $get('course_id')
                            ? Course::find($get('course_id'))?->groups()->orderBy('name')->pluck('name', 'groups.id') ?? []
                            : [])
                        ->searchable()
                        ->placeholder('Все студенты курса')
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => $this->refreshBaseRevenue($get, $set)),
                ]),

                Forms\Components\Placeholder::make('block_details')
                    ->label('Период блока и вошедшие оплаты')
                    ->content(function (Forms\Get $get) {
                        $courseId = $get('course_id');
                        $blockNumber = $get('block_number');
                        if (! $courseId || ! $blockNumber) {
                            return new HtmlString('<span class="text-sm text-gray-400">Выберите курс и блок…</span>');
                        }

                        $block = CourseBlock::query()
                            ->where('course_id', $courseId)
                            ->where('number', $blockNumber)
                            ->first();
                        $groupId = $get('group_id') ? (int) $get('group_id') : null;
                        $detail = app(TeacherSalaryService::class)
                            ->blockGroupRevenueDetail((int) $courseId, (int) $blockNumber, $groupId);

                        return view('filament.teacher-salaries.revenue-lines', [
                            'block' => $block,
                            'detail' => $detail,
                        ]);
                    }),

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('base_revenue')
                        ->label('Сумма за блок (₽)')
                        ->numeric()
                        ->required()
                        ->live(onBlur: true)
                        ->helperText('Авто по группе+блоку, можно поправить.'),

                    Forms\Components\TextInput::make('coefficient')
                        ->label('Коэффициент')
                        ->numeric()
                        ->required()
                        ->suffix('%')
                        ->live(onBlur: true)
                        ->helperText('Напр. 92 (минус ~8%).'),

                    Forms\Components\TextInput::make('teacher_percent')
                        ->label('Процент препода')
                        ->numeric()
                        ->required()
                        ->suffix('%')
                        ->live(onBlur: true)
                        ->helperText('Из ставки курса.'),
                ]),

                Forms\Components\Repeater::make('extras')
                    ->label('Допзанятия')
                    ->schema([
                        Forms\Components\TextInput::make('description')
                            ->label('Описание')
                            ->placeholder('Доп. занятие'),
                        Forms\Components\TextInput::make('count')
                            ->label('Кол-во')
                            ->numeric()
                            ->default(1)
                            ->required(),
                        Forms\Components\TextInput::make('price')
                            ->label('Цена за занятие (₽)')
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(3)
                    ->addActionLabel('Добавить допзанятие')
                    ->live()
                    ->default([]),

                Forms\Components\TextInput::make('surcharge')
                    ->label('Доплата (₽)')
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true)
                    ->helperText('Фиксированная добавка к итогу, если расчёт не дотягивает до минимума. Идёт сверх формулы (без коэффициента и процента).'),

                Forms\Components\TextInput::make('deduction')
                    ->label('Удержание (₽)')
                    ->numeric()
                    ->default(0)
                    ->live(onBlur: true)
                    ->helperText('Фиксированный вычет из итога (штраф/аванс/корректировка). Вычитается из суммы как есть (без коэффициента и процента).'),

                Forms\Components\Placeholder::make('preview')
                    ->label('Итог к выплате')
                    ->content(function (Forms\Get $get): string {
                        $base = (float) ($get('base_revenue') ?: 0);
                        $coef = (float) ($get('coefficient') ?: 0);
                        $pct = (float) ($get('teacher_percent') ?: 0);
                        $extrasTotal = $this->extrasTotal($get('extras') ?? []);
                        $surcharge = (float) ($get('surcharge') ?: 0);
                        $deduction = (float) ($get('deduction') ?: 0);

                        $total = TeacherSalaryService::blockPayoutTotal($base, $coef, $pct, $extrasTotal, $surcharge, $deduction);
                        $fmt = fn ($v) => number_format((float) $v, 0, '.', ' ');

                        $formula = "({$fmt($base)} × {$coef}%) × {$pct}% + {$fmt($extrasTotal)} × {$coef}%";
                        if ($surcharge != 0.0) {
                            $formula .= " + доплата {$fmt($surcharge)}";
                        }
                        if ($deduction != 0.0) {
                            $formula .= " − удержание {$fmt(abs($deduction))}";
                        }

                        return $formula.' = '.$fmt($total).' ₽';
                    }),

                Forms\Components\Toggle::make('post_to_finance')
                    ->label('Провести в Финансах')
                    ->default(true)
                    ->helperText('Создаст транзакцию-отток в «Финансах» (тип «Выплата ЗП») на этом курсе и блоке.'),
            ])
            ->action(function (array $data): void {
                $course = $data['course_id'] ? Course::find($data['course_id']) : null;
                $base = (float) ($data['base_revenue'] ?? 0);
                $coef = (float) ($data['coefficient'] ?? 0);
                $pct = (float) ($data['teacher_percent'] ?? 0);
                $extras = $data['extras'] ?? [];
                $extrasTotal = $this->extrasTotal($extras);
                $surcharge = (float) ($data['surcharge'] ?? 0);
                $deduction = (float) ($data['deduction'] ?? 0);
                $total = TeacherSalaryService::blockPayoutTotal($base, $coef, $pct, $extrasTotal, $surcharge, $deduction);

                $blockNumber = (int) $data['block_number'];
                $courseId = (int) $data['course_id'];
                $period = $this->blockPeriodMonth($courseId, $blockNumber);

                $groupId = $data['group_id'] ? (int) $data['group_id'] : null;
                $detail = app(TeacherSalaryService::class)
                    ->blockGroupRevenueDetail($courseId, $blockNumber, $groupId);

                $block = CourseBlock::query()
                    ->where('course_id', $courseId)
                    ->where('number', $blockNumber)
                    ->first();

                $comment = sprintf(
                    'Блок %d%s: (%s × %s%%) × %s%% + %s × %s%%%s%s = %s ₽',
                    $blockNumber,
                    $course ? ' · '.$course->title : '',
                    number_format($base, 0, '.', ' '),
                    $coef,
                    $pct,
                    number_format($extrasTotal, 0, '.', ' '),
                    $coef,
                    $surcharge != 0.0 ? ' + доплата '.number_format($surcharge, 0, '.', ' ') : '',
                    $deduction != 0.0 ? ' − удержание '.number_format(abs($deduction), 0, '.', ' ') : '',
                    number_format($total, 0, '.', ' '),
                );

                $payout = Teacher::find($data['teacher_id'])?->payouts()->create([
                    'amount' => $total,
                    'paid_at' => now()->toDateString(),
                    'period_month' => $period,
                    'course_id' => $course?->id,
                    'salary_type' => 'percent',
                    'salary_value' => $pct,
                    'comment' => $comment,
                    'breakdown' => [
                        'course_id' => $course?->id,
                        'block_number' => $blockNumber,
                        'group_id' => $groupId,
                        'block_period' => [
                            'starts_at' => $block?->starts_at?->toDateString(),
                            'ends_at' => $block?->ends_at?->toDateString(),
                        ],
                        'base_revenue' => $base,
                        'coefficient' => $coef,
                        'teacher_percent' => $pct,
                        'extras' => array_values($extras),
                        'extras_total' => $extrasTotal,
                        'surcharge' => $surcharge,
                        'deduction' => abs($deduction),
                        'total' => $total,
                        // Какие оплаты автоматически вошли в сумму за блок (на момент расчёта).
                        'payments' => $detail['lines'],
                        'payments_total' => $detail['total'],
                    ],
                ]);

                if ($payout && ($data['post_to_finance'] ?? true)) {
                    app(TeacherPayoutPoster::class)->post($payout);
                }

                Notification::make()
                    ->title('Выплата записана')
                    ->body('Итог: '.number_format($total, 0, '.', ' ').' ₽')
                    ->success()
                    ->send();
            });
    }

    /**
     * Подставить «сумму за блок» из выручки группы за блок, когда выбраны
     * курс и блок.
     */
    private function refreshBaseRevenue(Forms\Get $get, Forms\Set $set): void
    {
        $courseId = $get('course_id');
        $blockNumber = $get('block_number');
        if (! $courseId || ! $blockNumber) {
            return;
        }

        $groupId = $get('group_id') ? (int) $get('group_id') : null;
        $revenue = app(TeacherSalaryService::class)
            ->blockGroupRevenue((int) $courseId, (int) $blockNumber, $groupId);

        $set('base_revenue', $revenue);
    }

    /**
     * Сумма допзанятий: Σ count × price.
     *
     * @param  array<int, array<string, mixed>>  $extras
     */
    private function extrasTotal(array $extras): float
    {
        $sum = 0.0;
        foreach ($extras as $row) {
            $sum += (float) ($row['count'] ?? 0) * (float) ($row['price'] ?? 0);
        }

        return $sum;
    }

    /**
     * Месяц блока (YYYY-MM) по CourseBlock.starts_at, иначе текущий.
     */
    private function blockPeriodMonth(int $courseId, int $blockNumber): string
    {
        $block = CourseBlock::query()
            ->where('course_id', $courseId)
            ->where('number', $blockNumber)
            ->first();

        return $block?->starts_at?->format('Y-m') ?? now()->format('Y-m');
    }

    /**
     * Выбранный в фильтре месяц (YYYY-MM), по умолчанию — текущий.
     */
    public function resolvePeriod(): string
    {
        $value = $this->tableFilters['period']['value'] ?? null;

        return $value ?: now()->format('Y-m');
    }

    /**
     * Сводка по конкретному преподавателю за выбранный период (мемоизирована).
     *
     * @return array<string, mixed>
     */
    private function salaryFor(int $teacherId): array
    {
        $period = $this->resolvePeriod();
        $this->summaryCache[$period] ??= app(TeacherSalaryService::class)->summaryForAll($period);

        return $this->summaryCache[$period][$teacherId] ?? [
            'earned_period' => 0.0,
            'earned_period_gross' => 0.0,
            'returns_period' => 0.0,
            'earned_all_time' => 0.0,
            'paid_all_time' => 0.0,
            'balance' => 0.0,
        ];
    }

    /**
     * Закрыт ли выбранный период у преподавателя (кэш на запрос).
     */
    private function isPeriodClosed(int $teacherId): bool
    {
        $period = $this->resolvePeriod();
        if (! isset($this->closedTeacherCache[$period])) {
            $this->closedTeacherCache[$period] = SalaryClosedPeriod::query()
                ->where('period_month', $period)
                ->pluck('teacher_id')
                ->mapWithKeys(fn ($id) => [(int) $id => 1])
                ->all();
        }

        return isset($this->closedTeacherCache[$period][$teacherId]);
    }

    private function periodLabel(): string
    {
        return \Illuminate\Support\Carbon::parse($this->resolvePeriod().'-01')->translatedFormat('F Y');
    }

    /**
     * Тултип «из чего минус»: список возвратов/расходов преподавателя за период.
     */
    private function returnsTooltip(int $teacherId): ?string
    {
        if ((float) $this->salaryFor($teacherId)['returns_period'] == 0.0) {
            return null;
        }

        $teacher = Teacher::with('courses')->find($teacherId);
        if (! $teacher) {
            return null;
        }

        [$start, $end] = $this->periodBounds($this->resolvePeriod());
        $lines = app(TeacherSalaryService::class)->returnsForTeacher($teacher, $start, $end);

        $parts = [];
        foreach ($lines as $l) {
            $row = $l['course_title'].': '.number_format((float) $l['amount'], 0, '.', ' ').' ₽';
            if ((float) $l['effect'] != 0.0) {
                $row .= ' (→ '.number_format((float) $l['effect'], 0, '.', ' ').' ₽ к ЗП)';
            }
            if (! empty($l['date'])) {
                $row .= ' · '.$l['date'];
            }
            if (! empty($l['note'])) {
                $row .= ' · '.$l['note'];
            }
            $parts[] = $row;
        }

        return $parts ? implode("\n", $parts) : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Teacher::query()->withCount('courses'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Преподаватель')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Model $r): string => '#'.$r->id),

                Tables\Columns\TextColumn::make('courses_count')
                    ->label('Курсов')
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('earned_period')
                    ->label('Начислено за месяц')
                    ->alignRight()
                    ->getStateUsing(fn (Model $r): string => $this->money($this->salaryFor((int) $r->id)['earned_period_gross'])),

                Tables\Columns\TextColumn::make('returns_period')
                    ->label('Возвраты за месяц')
                    ->alignRight()
                    ->color('danger')
                    ->getStateUsing(function (Model $r): string {
                        $returns = (float) $this->salaryFor((int) $r->id)['returns_period'];

                        return $returns != 0.0 ? $this->money($returns) : '—';
                    })
                    ->tooltip(fn (Model $r): ?string => $this->returnsTooltip((int) $r->id)),

                Tables\Columns\TextColumn::make('earned_all_time')
                    ->label('Начислено всего')
                    ->alignRight()
                    ->toggleable()
                    ->getStateUsing(fn (Model $r): string => $this->money($this->salaryFor((int) $r->id)['earned_all_time'])),

                Tables\Columns\TextColumn::make('paid_all_time')
                    ->label('Выплачено всего')
                    ->alignRight()
                    ->toggleable()
                    ->getStateUsing(fn (Model $r): string => $this->money($this->salaryFor((int) $r->id)['paid_all_time'])),

                Tables\Columns\TextColumn::make('balance')
                    ->label('К выплате')
                    ->alignRight()
                    ->weight('bold')
                    ->badge()
                    ->getStateUsing(fn (Model $r): string => $this->money($this->salaryFor((int) $r->id)['balance']))
                    ->color(fn (Model $r): string => $this->salaryFor((int) $r->id)['balance'] > 0 ? 'warning' : 'success'),

                Tables\Columns\IconColumn::make('period_closed')
                    ->label('Месяц закрыт')
                    ->alignCenter()
                    ->getStateUsing(fn (Model $r): bool => $this->isPeriodClosed((int) $r->id))
                    ->trueIcon('heroicon-s-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn (Model $r): string => $this->isPeriodClosed((int) $r->id)
                        ? 'Месяц закрыт: новые начисления переносятся в ближайший открытый.'
                        : 'Месяц открыт.'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->label('Период')
                    ->options(TeacherPayoutResource::periodOptions())
                    ->default(now()->format('Y-m'))
                    // Фильтр НЕ режет выборку преподов — он лишь хранит выбранный
                    // месяц, который читают колонки начислений/выплат за период.
                    ->query(fn ($query) => $query),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    $this->breakdownAction(),
                    $this->recordPayoutAction(),
                    $this->toggleCloseAction(),
                    $this->openCardAction(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(TeacherSalariesExporter::class)
                    ->label('Экспорт'),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('Преподавателей нет')
            ->emptyStateDescription('Заведите преподавателей и привяжите к ним курсы.');
    }

    private function breakdownAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('breakdown')
            ->label('Разбивка')
            ->icon('heroicon-o-table-cells')
            ->color('gray')
            ->modalHeading(fn (Model $r): string => 'Разбивка ЗП: '.$r->name)
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function (Model $r) {
                $teacher = Teacher::with('courses')->find($r->id);
                $period = $this->resolvePeriod();
                [$start, $end] = $this->periodBounds($period);
                $service = app(TeacherSalaryService::class);

                return view('filament.teacher-salaries.breakdown', [
                    'teacher' => $teacher,
                    'periodLabel' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $period)->translatedFormat('F Y'),
                    'breakdown' => $service->breakdownForTeacher($teacher, $start, $end),
                    'payments' => $service->paymentsForTeacher($teacher, $start, $end),
                ]);
            });
    }

    private function recordPayoutAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('record_payout')
            ->label('Записать выплату')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->modalHeading(fn (Model $r): string => 'Выплата: '.$r->name)
            ->fillForm(fn (): array => [
                'paid_at' => now()->toDateString(),
                'period_month' => $this->resolvePeriod(),
                'post_to_finance' => true,
            ])
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label('Сумма выплаты (₽)')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Forms\Components\DatePicker::make('paid_at')
                    ->label('Дата выплаты')
                    ->native(false)
                    ->required(),
                Forms\Components\Select::make('period_month')
                    ->label('За период')
                    ->options(TeacherPayoutResource::periodOptions())
                    ->required(),
                Forms\Components\Select::make('course_id')
                    ->label('Курс (необязательно)')
                    ->options(fn (Model $r) => Course::query()
                        ->where('teacher_id', $r->id)
                        ->orderBy('title')
                        ->pluck('title', 'id'))
                    ->helperText('Если выбрать — зафиксируем снимок ставки курса в выплате.'),
                Forms\Components\Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(2)
                    ->placeholder('Например: ЗП за март'),
                Forms\Components\Toggle::make('post_to_finance')
                    ->label('Провести в Финансах')
                    ->default(true)
                    ->helperText('Создаст транзакцию-отток в «Финансах» (тип «Выплата ЗП»).'),
            ])
            ->action(function (Model $r, array $data): void {
                $course = ! empty($data['course_id']) ? Course::find($data['course_id']) : null;

                $payout = Teacher::find($r->id)?->payouts()->create([
                    'amount' => $data['amount'],
                    'paid_at' => $data['paid_at'],
                    'period_month' => $data['period_month'] ?? null,
                    'course_id' => $course?->id,
                    'salary_type' => $course?->salary_type,
                    'salary_value' => $course?->salary_value,
                    'comment' => $data['comment'] ?? null,
                ]);

                if ($payout && ($data['post_to_finance'] ?? true)) {
                    app(TeacherPayoutPoster::class)->post($payout);
                }

                Notification::make()
                    ->title('Выплата записана')
                    ->success()
                    ->send();
            });
    }

    private function toggleCloseAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggle_close')
            ->label(fn (Model $r): string => $this->isPeriodClosed((int) $r->id)
                ? 'Открыть '.$this->periodLabel()
                : 'Закрыть '.$this->periodLabel())
            ->icon(fn (Model $r): string => $this->isPeriodClosed((int) $r->id)
                ? 'heroicon-o-lock-open'
                : 'heroicon-o-lock-closed')
            ->color(fn (Model $r): string => $this->isPeriodClosed((int) $r->id) ? 'gray' : 'danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Model $r): string => ($this->isPeriodClosed((int) $r->id) ? 'Открыть месяц: ' : 'Закрыть месяц: ').$r->name)
            ->modalDescription(fn (Model $r): string => $this->isPeriodClosed((int) $r->id)
                ? 'Месяц снова станет открытым — отложенные начисления вернутся в него.'
                : 'Месяц будет закрыт: поздние оплаты/обещания/рассрочки за уже прошедшие блоки этого месяца будут переноситься в ближайший открытый месяц.')
            ->action(function (Model $r): void {
                $period = $this->resolvePeriod();
                $existing = SalaryClosedPeriod::query()
                    ->where('teacher_id', $r->id)
                    ->where('period_month', $period)
                    ->first();

                if ($existing) {
                    $existing->delete();
                    $title = 'Месяц открыт';
                } else {
                    SalaryClosedPeriod::create([
                        'teacher_id' => $r->id,
                        'period_month' => $period,
                        'closed_at' => now(),
                        'closed_by' => auth()->id(),
                    ]);
                    $title = 'Месяц закрыт';
                }

                $this->closedTeacherCache = [];
                $this->summaryCache = [];

                Notification::make()->title($title.' · '.$this->periodLabel())->success()->send();
            });
    }

    private function openCardAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('open_card')
            ->label('Карточка преподавателя')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Model $r): string => TeacherResource::getUrl('edit', ['record' => $r->id]))
            ->openUrlInNewTab();
    }

    private function money(float $value): string
    {
        return number_format($value, 0, '.', ' ').' ₽';
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function periodBounds(string $period): array
    {
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
