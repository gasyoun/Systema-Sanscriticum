<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Exports\TeacherSalariesExporter;
use App\Filament\Resources\TeacherPayoutResource;
use App\Filament\Resources\TeacherResource;
use App\Filament\Widgets\TeacherSalariesTotalWidget;
use App\Mail\TeacherPayoutReportMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\SalaryClosedPeriod;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\CurrencyRateProvider;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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
        return RoleGate::accounting();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::accounting();
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
                // Снимок модели ЗП выбранного курса — управляет видимостью полей и формулой.
                Forms\Components\Hidden::make('salary_type')->default('percent'),
                // Валюта выплаты преподавателя (PayPal) — управляет блоком конвертации.
                Forms\Components\Hidden::make('payout_currency'),

                Forms\Components\Select::make('teacher_id')
                    ->label('Преподаватель')
                    ->options(fn () => Teacher::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set): void {
                        $set('course_id', null);
                        // Валюта выплаты преподавателя управляет блоком «Курс PayPal».
                        $set('payout_currency', $state ? Teacher::find($state)?->payout_currency : null);
                    }),

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
                        $type = (string) ($course?->salary_type ?: 'percent');
                        $set('salary_type', $type);

                        // Для фикс-моделей salary_value — это ставка в ₽, не процент.
                        if ($this->isFixedModel($type)) {
                            $set('fixed_rate', $course?->salary_value);
                            $set('teacher_percent', null);
                        } else {
                            $set('teacher_percent', $course?->salary_value);
                            $set('fixed_rate', null);
                        }

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
                    // percent-модели: выручка за блок.
                    Forms\Components\TextInput::make('base_revenue')
                        ->label('Сумма за блок (₽)')
                        ->numeric()
                        ->visible(fn (Forms\Get $get) => ! $this->isFixedModel($get('salary_type')))
                        ->required(fn (Forms\Get $get) => ! $this->isFixedModel($get('salary_type')))
                        ->live(onBlur: true)
                        ->helperText('Авто по группе+блоку, можно поправить.'),

                    // фикс-модели: ставка из курса (₽).
                    Forms\Components\TextInput::make('fixed_rate')
                        ->label(fn (Forms\Get $get) => match ($get('salary_type')) {
                            'fix_per_student' => 'Ставка за студента (₽)',
                            'fix_per_block' => 'Ставка за блок (₽)',
                            'fix_total' => 'Ставка за курс (₽)',
                            default => 'Ставка (₽)',
                        })
                        ->numeric()
                        ->visible(fn (Forms\Get $get) => $this->isFixedModel($get('salary_type')))
                        ->required(fn (Forms\Get $get) => $this->isFixedModel($get('salary_type')))
                        ->live(onBlur: true)
                        ->helperText('Из ставки курса.'),

                    // только fix_per_student: число студентов блока/группы.
                    Forms\Components\TextInput::make('student_count')
                        ->label('Студентов')
                        ->numeric()
                        ->visible(fn (Forms\Get $get) => $get('salary_type') === 'fix_per_student')
                        ->required(fn (Forms\Get $get) => $get('salary_type') === 'fix_per_student')
                        ->live(onBlur: true)
                        ->helperText('Авто по блоку+группе, можно поправить.'),

                    Forms\Components\TextInput::make('coefficient')
                        ->label('Коэффициент')
                        ->numeric()
                        ->suffix('%')
                        ->live(onBlur: true)
                        ->helperText('Необязательно. Пусто = 100%. Напр. 92 (минус ~8%).'),

                    Forms\Components\TextInput::make('teacher_percent')
                        ->label('Процент препода')
                        ->numeric()
                        ->suffix('%')
                        ->visible(fn (Forms\Get $get) => ! $this->isFixedModel($get('salary_type')))
                        ->required(fn (Forms\Get $get) => ! $this->isFixedModel($get('salary_type')))
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
                        $state = [
                            'salary_type' => $get('salary_type'),
                            'course_id' => $get('course_id'),
                            'base_revenue' => $get('base_revenue'),
                            'teacher_percent' => $get('teacher_percent'),
                            'fixed_rate' => $get('fixed_rate'),
                            'student_count' => $get('student_count'),
                        ];
                        ['base' => $base, 'pct' => $pct] = $this->effectiveBaseAndPct($state);
                        $coef = $this->normalizeCoef($get('coefficient'));
                        $extrasTotal = $this->extrasTotal($get('extras') ?? []);
                        $surcharge = (float) ($get('surcharge') ?: 0);
                        $deduction = (float) ($get('deduction') ?: 0);

                        $total = TeacherSalaryService::blockPayoutTotal($base, $coef, $pct, $extrasTotal, $surcharge, $deduction);

                        return $this->formulaText($state, $coef, $extrasTotal, $surcharge, $deduction)
                            .' = '.number_format($total, 0, '.', ' ').' ₽';
                    }),

                // === Валютная конвертация (PayPal) — только если у преподавателя задана валюта ===
                Forms\Components\DatePicker::make('rate_date')
                    ->label('Курс на дату')
                    ->native(false)
                    ->default(now())
                    ->maxDate(now())
                    ->visible(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->required(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->live()
                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => $this->fillExchangeRate($get, $set))
                    ->helperText('Дата, на которую берётся курс. По ней курс подтягивается автоматически.'),

                Forms\Components\TextInput::make('exchange_rate')
                    ->label(fn (Forms\Get $get) => 'Курс PayPal (₽ за 1 '.TeacherPayout::currencySymbol($get('payout_currency')).')')
                    ->numeric()
                    ->visible(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->required(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->live(onBlur: true)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('fetchRate')
                            ->icon('heroicon-m-arrow-path')
                            ->tooltip('Подтянуть курс на выбранную дату')
                            ->action(fn (Forms\Get $get, Forms\Set $set) => $this->fillExchangeRate($get, $set, notify: true)),
                    )
                    ->helperText('Подтягивается с exchangerate.host на выбранную дату — можно поправить вручную.'),

                Forms\Components\Placeholder::make('foreign_preview')
                    ->label('Сумма в валюте')
                    ->visible(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->content(function (Forms\Get $get): string {
                        $rate = (float) ($get('exchange_rate') ?: 0);
                        if ($rate <= 0) {
                            return '— укажите курс';
                        }
                        $foreign = round($this->currentTotal($get) / $rate, 2);

                        return number_format($foreign, 2, '.', ' ').' '.TeacherPayout::currencySymbol($get('payout_currency'));
                    }),

                Forms\Components\Toggle::make('email_report')
                    ->label('Отправить отчёт преподавателю на почту')
                    ->default(false)
                    ->visible(fn (Forms\Get $get) => ! empty($get('payout_currency')))
                    ->helperText('Прозрачная расшифровка (студенты × ставка = ₽, курс, итог в валюте) уйдёт на email преподавателя.'),

                Forms\Components\Toggle::make('post_to_finance')
                    ->label('Провести в Финансах')
                    ->default(true)
                    ->helperText('Создаст транзакцию-отток в «Финансах» (тип «Выплата ЗП») на этом курсе и блоке.'),
            ])
            ->action(function (array $data): void {
                $course = $data['course_id'] ? Course::find($data['course_id']) : null;
                $salaryType = (string) ($data['salary_type'] ?? 'percent');
                $isFixed = $this->isFixedModel($salaryType);

                ['base' => $base, 'pct' => $pct] = $this->effectiveBaseAndPct($data);
                $coef = $this->normalizeCoef($data['coefficient'] ?? null);
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
                    'Блок %d%s: %s = %s ₽',
                    $blockNumber,
                    $course ? ' · '.$course->title : '',
                    $this->formulaText($data, $coef, $extrasTotal, $surcharge, $deduction),
                    number_format($total, 0, '.', ' '),
                );

                $fixedRate = (float) ($data['fixed_rate'] ?? 0);

                // Число слушателей блока/группы — для прозрачного отчёта («X студентов»),
                // независимо от модели ЗП. Для fix_per_student источник правды — ручной ввод.
                $studentCount = $salaryType === 'fix_per_student'
                    ? (int) ($data['student_count'] ?? 0)
                    : collect($detail['lines'])->pluck('user_id')->unique()->count();

                // Состав слушателей для письма: платные (платёж > 0) и льготники
                // (нулевая оплата). Считаем из платежей, независимо от модели ЗП.
                $paidStudentCount = collect($detail['lines'])->pluck('user_id')->unique()->count();
                $freeStudentCount = app(TeacherSalaryService::class)
                    ->blockFreeStudentCount($courseId, $blockNumber, $groupId);

                // Валютная конвертация (PayPal): курс на выбранную дату, сумма в валюте справочная.
                $currency = $data['payout_currency'] ?: null;
                $rate = (float) ($data['exchange_rate'] ?? 0);
                $rateDate = $data['rate_date'] ?? null;
                $amountForeign = ($currency && $rate > 0) ? round($total / $rate, 2) : null;

                $payout = Teacher::find($data['teacher_id'])?->payouts()->create([
                    'amount' => $total,
                    'paid_at' => now()->toDateString(),
                    'period_month' => $period,
                    'course_id' => $course?->id,
                    'salary_type' => $salaryType,
                    'salary_value' => $isFixed ? $fixedRate : $pct,
                    'payout_currency' => $amountForeign !== null ? $currency : null,
                    'exchange_rate' => $amountForeign !== null ? $rate : null,
                    'rate_date' => $amountForeign !== null ? $rateDate : null,
                    'amount_foreign' => $amountForeign,
                    'comment' => $comment,
                    'breakdown' => [
                        'course_id' => $course?->id,
                        'block_number' => $blockNumber,
                        'group_id' => $groupId,
                        'block_period' => [
                            'starts_at' => $block?->starts_at?->toDateString(),
                            'ends_at' => $block?->ends_at?->toDateString(),
                        ],
                        'salary_type' => $salaryType,
                        'base_revenue' => $base,
                        'coefficient' => $coef,
                        'teacher_percent' => $pct,
                        'fixed_rate' => $isFixed ? $fixedRate : null,
                        'student_count' => $studentCount,
                        'paid_student_count' => $paidStudentCount,
                        'free_student_count' => $freeStudentCount,
                        'extras' => array_values($extras),
                        'extras_total' => $extrasTotal,
                        'surcharge' => $surcharge,
                        'deduction' => abs($deduction),
                        'total' => $total,
                        'payout_currency' => $amountForeign !== null ? $currency : null,
                        'exchange_rate' => $amountForeign !== null ? $rate : null,
                        'rate_date' => $amountForeign !== null ? $rateDate : null,
                        'amount_foreign' => $amountForeign,
                        // Какие оплаты автоматически вошли в сумму за блок (на момент расчёта).
                        'payments' => $detail['lines'],
                        'payments_total' => $detail['total'],
                    ],
                ]);

                if ($payout && ($data['post_to_finance'] ?? true)) {
                    app(TeacherPayoutPoster::class)->post($payout);
                }

                // Прозрачный отчёт преподавателю на почту (по галочке, только при конвертации).
                $teacher = $payout?->teacher;
                if ($payout && ($data['email_report'] ?? false) && $teacher?->email) {
                    Mail::to($teacher->email)->queue(new TeacherPayoutReportMail($payout->id));
                }

                Notification::make()
                    ->title('Выплата записана')
                    ->body('Итог: '.number_format($total, 0, '.', ' ').' ₽'
                        .($amountForeign !== null ? ' ≈ '.number_format($amountForeign, 2, '.', ' ').' '.TeacherPayout::currencySymbol($currency) : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * Подтянуть курс PayPal на выбранную дату с exchangerate.host и записать в
     * поле exchange_rate. Поле остаётся редактируемым. Если курс не получен
     * (нет ключа / API недоступен / дата без данных) — оставляем как есть; при
     * $notify (нажата кнопка «Подтянуть») показываем предупреждение.
     */
    private function fillExchangeRate(Forms\Get $get, Forms\Set $set, bool $notify = false): void
    {
        $currency = $get('payout_currency');
        $date = $get('rate_date');

        if (empty($currency) || empty($date)) {
            return;
        }

        $rate = app(CurrencyRateProvider::class)->rublesPerUnit($currency, Carbon::parse($date));

        if ($rate !== null) {
            $set('exchange_rate', $rate);

            if ($notify) {
                Notification::make()
                    ->title('Курс подтянут: '.$rate.' ₽ / '.TeacherPayout::currencySymbol($currency))
                    ->success()
                    ->send();
            }

            return;
        }

        if ($notify) {
            Notification::make()
                ->title('Не удалось получить курс')
                ->body('Проверьте ключ exchangerate.host или введите курс вручную.')
                ->warning()
                ->send();
        }
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
        $detail = app(TeacherSalaryService::class)
            ->blockGroupRevenueDetail((int) $courseId, (int) $blockNumber, $groupId);

        $set('base_revenue', $detail['total']);

        // Для «фикс за студента» подставляем число студентов блока/группы.
        if ($get('salary_type') === 'fix_per_student') {
            $set('student_count', collect($detail['lines'])->pluck('user_id')->unique()->count());
        }
    }

    /** Фикс-модели ЗП (ставка в ₽, без процента и без выручки). */
    private function isFixedModel(?string $type): bool
    {
        return in_array($type, ['fix_per_block', 'fix_total', 'fix_per_student'], true);
    }

    /** Коэффициент: пусто ⇒ 100% (без скидки). */
    private function normalizeCoef(mixed $raw): float
    {
        return ($raw === null || $raw === '') ? 100.0 : (float) $raw;
    }

    /** Текущий итог выплаты (₽) из состояния формы — для превью суммы в валюте. */
    private function currentTotal(Forms\Get $get): float
    {
        $state = [
            'salary_type' => $get('salary_type'),
            'course_id' => $get('course_id'),
            'base_revenue' => $get('base_revenue'),
            'teacher_percent' => $get('teacher_percent'),
            'fixed_rate' => $get('fixed_rate'),
            'student_count' => $get('student_count'),
        ];
        ['base' => $base, 'pct' => $pct] = $this->effectiveBaseAndPct($state);

        return TeacherSalaryService::blockPayoutTotal(
            $base,
            $this->normalizeCoef($get('coefficient')),
            $pct,
            $this->extrasTotal($get('extras') ?? []),
            (float) ($get('surcharge') ?: 0),
            (float) ($get('deduction') ?: 0),
        );
    }

    /**
     * Эффективные база и процент для blockPayoutTotal по модели ЗП курса.
     * Фикс-модели сводятся к pct=100 и базе из ставки (× студентов / ÷ блоки).
     *
     * @param  array<string, mixed>  $s  состояние формы
     * @return array{base: float, pct: float}
     */
    private function effectiveBaseAndPct(array $s): array
    {
        $type = (string) ($s['salary_type'] ?? 'percent');
        $rate = (float) ($s['fixed_rate'] ?? 0);

        return match ($type) {
            'fix_per_student' => ['base' => $rate * (float) ($s['student_count'] ?? 0), 'pct' => 100.0],
            'fix_per_block' => ['base' => $rate, 'pct' => 100.0],
            'fix_total' => ['base' => $rate / max(1, $this->courseBlockCount((int) ($s['course_id'] ?? 0))), 'pct' => 100.0],
            default => ['base' => (float) ($s['base_revenue'] ?? 0), 'pct' => (float) ($s['teacher_percent'] ?? 0)],
        };
    }

    private function courseBlockCount(int $courseId): int
    {
        return $courseId ? CourseBlock::where('course_id', $courseId)->count() : 0;
    }

    /**
     * Текст формулы для превью и комментария — по модели ЗП.
     *
     * @param  array<string, mixed>  $s  состояние формы
     */
    private function formulaText(array $s, float $coef, float $extrasTotal, float $surcharge, float $deduction): string
    {
        $fmt = fn ($v) => number_format((float) $v, 0, '.', ' ');
        $type = (string) ($s['salary_type'] ?? 'percent');
        $coefPart = $coef != 100.0 ? " × {$fmt($coef)}%" : '';

        $core = match ($type) {
            'fix_per_student' => "{$fmt($s['fixed_rate'] ?? 0)} ₽ × {$fmt($s['student_count'] ?? 0)} студ.{$coefPart}",
            'fix_per_block' => "{$fmt($s['fixed_rate'] ?? 0)} ₽{$coefPart}",
            'fix_total' => "{$fmt($s['fixed_rate'] ?? 0)} ₽ ÷ блоки{$coefPart}",
            default => "({$fmt($s['base_revenue'] ?? 0)} × {$fmt($coef)}%) × {$fmt($s['teacher_percent'] ?? 0)}%",
        };

        if ($extrasTotal != 0.0) {
            $core .= " + {$fmt($extrasTotal)} × {$fmt($coef)}%";
        }
        if ($surcharge != 0.0) {
            $core .= " + доплата {$fmt($surcharge)}";
        }
        if ($deduction != 0.0) {
            $core .= " − удержание {$fmt(abs($deduction))}";
        }

        return $core;
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
            'advances_outstanding' => 0.0,
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

                Tables\Columns\TextColumn::make('advances_outstanding')
                    ->label('Авансы (не зачтены)')
                    ->alignRight()
                    ->toggleable()
                    ->getStateUsing(fn (Model $r): string => $this->money($this->salaryFor((int) $r->id)['advances_outstanding']))
                    ->color(fn (Model $r): string => ($this->salaryFor((int) $r->id)['advances_outstanding'] ?? 0) > 0 ? 'warning' : 'gray')
                    ->tooltip('Деньги выданы авансом, но ещё не зачтены в счёт ЗП. Зачёт — при записи выплаты.'),

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
                    $this->issueAdvanceAction(),
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
            ->fillForm(function (Model $r): array {
                $s = $this->salaryFor((int) $r->id);
                $advances = (float) ($s['advances_outstanding'] ?? 0);

                return [
                    'paid_at' => now()->toDateString(),
                    'period_month' => $this->resolvePeriod(),
                    'post_to_finance' => true,
                    // По умолчанию зачитываем непогашенный аванс и предлагаем доплату
                    // = остаток − аванс (аванс уже выдан деньгами ранее).
                    'settle_advances' => $advances > 0,
                    'amount' => max(0, round((float) ($s['balance'] ?? 0) - $advances, 2)),
                ];
            })
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
                Forms\Components\Toggle::make('settle_advances')
                    ->label(fn (Model $r): string => 'Зачесть аванс ('.$this->money($this->salaryFor((int) $r->id)['advances_outstanding'] ?? 0).')')
                    ->helperText('Пометит ранее выданные авансы зачтёнными. Сумма выше — доплата (остаток за вычетом аванса).')
                    ->visible(fn (Model $r): bool => ($this->salaryFor((int) $r->id)['advances_outstanding'] ?? 0) > 0),
                Forms\Components\Toggle::make('post_to_finance')
                    ->label('Провести в Финансах')
                    ->default(true)
                    ->helperText('Создаст транзакцию-отток в «Финансах» (тип «Выплата ЗП»).'),
            ])
            ->action(function (Model $r, array $data): void {
                $course = ! empty($data['course_id']) ? Course::find($data['course_id']) : null;

                $payout = Teacher::find($r->id)?->payouts()->create([
                    'amount' => $data['amount'],
                    'type' => TeacherPayout::TYPE_REGULAR,
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

                // Зачёт авансов: помечаем непогашенные авансы преподавателя зачтёнными.
                if (! empty($data['settle_advances'])) {
                    TeacherPayout::query()
                        ->where('teacher_id', $r->id)
                        ->unsettledAdvances()
                        ->update(['settled_at' => now(), 'settled_by' => auth()->id()]);
                }

                Notification::make()
                    ->title('Выплата записана')
                    ->success()
                    ->send();
            });
    }

    /**
     * Выдать аванс: реальные деньги уходят (проводятся в «Финансы»), но к ЗП
     * аванс не зачтён — висит как «не зачтён», пока не зачтут при полной выплате.
     */
    private function issueAdvanceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('issue_advance')
            ->label('Выдать аванс')
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->modalHeading(fn (Model $r): string => 'Аванс: '.$r->name)
            ->fillForm(fn (): array => [
                'paid_at' => now()->toDateString(),
                'post_to_finance' => true,
            ])
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label('Сумма аванса (₽)')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Forms\Components\DatePicker::make('paid_at')
                    ->label('Дата выдачи')
                    ->native(false)
                    ->required(),
                Forms\Components\Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(2)
                    ->placeholder('Например: аванс в счёт ЗП за июнь'),
                Forms\Components\Toggle::make('post_to_finance')
                    ->label('Провести в Финансах')
                    ->default(true)
                    ->helperText('Создаст транзакцию-отток в «Финансах» (реальные деньги выданы).'),
            ])
            ->action(function (Model $r, array $data): void {
                $payout = Teacher::find($r->id)?->payouts()->create([
                    'amount' => $data['amount'],
                    'type' => TeacherPayout::TYPE_ADVANCE,
                    'paid_at' => $data['paid_at'],
                    'comment' => $data['comment'] ?? null,
                ]);

                if ($payout && ($data['post_to_finance'] ?? true)) {
                    app(TeacherPayoutPoster::class)->post($payout);
                }

                Notification::make()
                    ->title('Аванс выдан')
                    ->body('Аванс висит как «не зачтён» — зачтите его при записи полной выплаты.')
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
