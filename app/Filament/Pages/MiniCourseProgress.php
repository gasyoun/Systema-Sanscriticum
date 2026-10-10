<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\User;
use App\Support\MiniCourseProgress as MiniCourseProgressData;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Прогресс мини-курса» — воронка «Подготовительной группы»: один ряд
 * на студента, этапы как колонки (квизы, уроки, домашки, финал, промокод).
 *
 * Источник данных — MiniCourseProgressData::rows() (bulk-агрегат); таблица
 * строится по User-запросу, колонки читают из агрегата. Выгрузка CSV —
 * для рекламного отдела (связка «кампания → этапы → оплата» через
 * signup_source/UTM-атрибуцию пользователя).
 */
class MiniCourseProgress extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Прогресс мини-курса';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 27;

    protected static ?string $title = 'Прогресс мини-курса';

    protected static ?string $slug = 'mini-course-progress';

    protected static string $view = 'filament.pages.mini-course-progress';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public ?Collection $progressRows = null;

    public ?Course $miniCourse = null;

    public function mount(): void
    {
        $this->miniCourse = MiniCourseProgressData::course();
        $this->progressRows = $this->miniCourse
            ? MiniCourseProgressData::rows($this->miniCourse->id)
            : collect();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Выгрузить CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->studentQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Студент')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->email),
                TextColumn::make('signup_source')
                    ->label('Источник')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('enrolled_at')
                    ->label('Записан')
                    ->state(fn ($record) => $this->progressRows()[$record->id]->enrolled_at?->timezone(config('app.timezone'))->translatedFormat('d.m.y'))
                    ->sortable(),
                TextColumn::make('lessons_progress')
                    ->label('Уроки')
                    ->state(fn ($record) => $this->progressRows()[$record->id]->lessons_completed.' из '.$this->progressRows()[$record->id]->lessons_total)
                    ->alignCenter(),
                TextColumn::make('quiz_1')
                    ->label('Этап 1')
                    ->state(fn ($record) => $this->quizState($record, 1))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('quiz_2')
                    ->label('Этап 2')
                    ->state(fn ($record) => $this->quizState($record, 2))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('quiz_3')
                    ->label('Этап 3')
                    ->state(fn ($record) => $this->quizState($record, 3))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('quiz_4')
                    ->label('Этап 4')
                    ->state(fn ($record) => $this->quizState($record, 4))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('quiz_5')
                    ->label('Этап 5')
                    ->state(fn ($record) => $this->quizState($record, 5))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('final')
                    ->label('🏁 Финал')
                    ->state(fn ($record) => $this->finalState($record))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('homework')
                    ->label('ДЗ')
                    ->state(fn ($record) => $this->homeworkState($record))
                    ->html()
                    ->alignCenter(),
                TextColumn::make('promo')
                    ->label('Промокод')
                    ->state(fn ($record) => $this->promoState($record))
                    ->html(),
            ])
            ->filters([
                SelectFilter::make('final')
                    ->label('Итоговый тест')
                    ->options([
                        'passed' => 'Сдан',
                        'not_passed' => 'Не сдан / нет попыток',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if (! in_array($value, ['passed', 'not_passed'], true)) {
                            return;
                        }

                        $query->whereExists($this->finalSubquery($value === 'passed'));
                    }),
                SelectFilter::make('promo')
                    ->label('Промокод грамматики')
                    ->options([
                        'issued' => 'Выдан',
                        'redeemed' => 'Использован (оплачен)',
                        'none' => 'Не выдан',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if (! in_array($value, ['issued', 'redeemed', 'none'], true)) {
                            return;
                        }

                        $prefix = (string) config('mini_courses.promo_code_prefix', 'PREP50');

                        $query->whereExists(function ($q) use ($prefix, $value): void {
                            $q->from('promo_codes')
                                ->whereColumn('promo_codes.code', \DB::raw("CONCAT('".$prefix."-', users.id)"))
                                ->when($value === 'redeemed', fn ($qq) => $qq->whereExists(function ($qp) {
                                    $qp->from('payments')
                                        ->whereColumn('payments.promo_code_id', 'promo_codes.id')
                                        ->where('payments.status', 'paid');
                                }))
                                ->when($value === 'none', fn ($qq) => $qq->where('promo_codes.id', 0));
                        }, $value !== 'none');

                        if ($value === 'none') {
                            $query->whereNotExists(function ($q) use ($prefix): void {
                                $q->select(\DB::raw(1))
                                    ->from('promo_codes')
                                    ->whereColumn('promo_codes.code', \DB::raw("CONCAT('".$prefix."-', users.id)"));
                            });
                        }
                    }),
                TernaryFilter::make('has_homework')
                    ->label('Есть сданная домашка')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(function ($q): void {
                            $q->from('homework_submissions')
                                ->whereColumn('homework_submissions.user_id', 'users.id')
                                ->where('homework_submissions.course_id', MiniCourseProgressData::course()?->id ?? 0)
                                ->whereNotIn('homework_submissions.status', ['draft']);
                        }),
                        false: fn (Builder $query) => $query->whereNotExists(function ($q): void {
                            $q->from('homework_submissions')
                                ->whereColumn('homework_submissions.user_id', 'users.id')
                                ->where('homework_submissions.course_id', MiniCourseProgressData::course()?->id ?? 0)
                                ->whereNotIn('homework_submissions.status', ['draft']);
                        }),
                    ),
            ])
            ->defaultSort('enrolled_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    /** Студенты, записанные на мини-курс (course_user). */
    private function studentQuery(): Relation|Builder
    {
        $course = MiniCourseProgressData::course();

        return User::query()
            ->join('course_user', function ($join) use ($course): void {
                $join->on('course_user.user_id', '=', 'users.id')
                    ->where('course_user.course_id', $course?->id ?? 0);
            })
            ->select('users.*', 'course_user.created_at AS enrolled_at')
            ->orderByDesc('course_user.created_at');
    }

    private function progressRows(): Collection
    {
        return $this->progressRows ?? collect();
    }

    private function quizState($record, int $block): string
    {
        $quiz = $this->progressRows()[$record->id]->quizzes[$block] ?? null;

        if ($quiz === null || $quiz['attempts'] === 0) {
            return '<span style="color:#9ca3af">—</span>';
        }

        if ($quiz['passed']) {
            return '<span style="color:#059669;font-weight:800">✅ '.$quiz['score'].'/'.$quiz['total'].'</span>';
        }

        return '<span style="color:#d97706;font-weight:700">'.$quiz['score'].'/'.$quiz['total'].'</span>';
    }

    private function finalState($record): string
    {
        $quiz = $this->progressRows()[$record->id]->quizzes[6] ?? null;

        if ($quiz === null || $quiz['attempts'] === 0) {
            return '<span style="color:#9ca3af">—</span>';
        }

        if ($quiz['passed']) {
            return '<span style="color:#4f46e5;font-weight:800">🎓 '.$quiz['score'].'/'.$quiz['total'].'</span>';
        }

        return '<span style="color:#d97706;font-weight:700">'.$quiz['score'].'/'.$quiz['total'].'</span>';
    }

    private function homeworkState($record): string
    {
        $row = $this->progressRows()[$record->id];

        if ($row->hw_submitted === 0) {
            return '<span style="color:#9ca3af">—</span>';
        }

        return '<span style="font-weight:700">'.$row->hw_accepted.'</span> / '.$row->hw_submitted;
    }

    private function promoState($record): string
    {
        $row = $this->progressRows()[$record->id];

        if ($row->promo_code === null) {
            return '<span style="color:#9ca3af">—</span>';
        }

        if ($row->promo_redeemed) {
            return '<span style="color:#059669;font-weight:800">оплачен ✅</span>';
        }

        return '<span class="font-mono" style="font-weight:700">'.$row->promo_code.'</span>';
    }

    private function finalSubquery(bool $passed): \Closure
    {
        return function ($q) use ($passed): void {
            $q->from('course_quiz_attempts')
                ->join('course_quizzes', 'course_quizzes.id', '=', 'course_quiz_attempts.course_quiz_id')
                ->whereColumn('course_quiz_attempts.user_id', 'users.id')
                ->where('course_quizzes.course_id', MiniCourseProgressData::course()?->id ?? 0)
                ->where('course_quizzes.block_number', '>', function ($qb): void {
                    $qb->selectRaw('COALESCE(MAX(number), 0)')
                        ->from('course_blocks')
                        ->where('course_blocks.course_id', MiniCourseProgressData::course()?->id ?? 0);
                })
                ->when($passed, fn ($qq) => $qq->where('course_quiz_attempts.passed', true));
        };
    }

    /** CSV для рекламного отдела: те же колонки, что в таблице. */
    private function exportCsv(): StreamedResponse
    {
        $courseId = MiniCourseProgressData::course()?->id ?? 0;
        $rows = MiniCourseProgress::rows($courseId);
        $filename = 'mini-course-progress-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM для Excel
            fputcsv($out, [
                'Студент', 'Email', 'Источник', 'Регистрация', 'Записан',
                'Уроки', 'Этап 1', 'Этап 2', 'Этап 3', 'Этап 4', 'Этап 5',
                'Финал', 'ДЗ принято/всего', 'Промокод', 'Промокод использован',
            ], ';');

            foreach ($rows as $row) {
                $quizValue = fn (int $block): string => match (true) {
                    ($row->quizzes[$block]['attempts'] ?? 0) === 0 => '—',
                    ($row->quizzes[$block]['passed'] ?? false) => 'зачёт '.$row->quizzes[$block]['score'].'/'.$row->quizzes[$block]['total'],
                    default => $row->quizzes[$block]['score'].'/'.$row->quizzes[$block]['total'],
                };

                fputcsv($out, [
                    $row->name,
                    $row->email,
                    $row->signup_source,
                    optional($row->registered_at)->format('Y-m-d'),
                    optional($row->enrolled_at)->format('Y-m-d'),
                    $row->lessons_completed.'/'.$row->lessons_total,
                    $quizValue(1),
                    $quizValue(2),
                    $quizValue(3),
                    $quizValue(4),
                    $quizValue(5),
                    $quizValue(6),
                    $row->hw_accepted.'/'.$row->hw_submitted,
                    $row->promo_code ?? '—',
                    $row->promo_redeemed ? 'да' : 'нет',
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
