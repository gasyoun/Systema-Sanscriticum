<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Course;
use App\Models\CourseAccessWindow;
use App\Models\Payment;
use App\Models\User;
use App\Support\RoleGate;
use App\Support\Roles;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * H4468 — окна доступа «студент × курс» (course_access_windows) в карточке
 * студента. Рулинг MG 09-09-2026: вечный доступ к курсам Парибка закрыт,
 * доступ выдаётся окном — пакет 30 дней по запросу студента; продления —
 * ещё по 30 дней; вечное окно (ends_at NULL) — только по прямому слову MG.
 *
 * Скоуп UI ограничен: курс должен быть (а) куплен студентом (paid real),
 * (б) входить в config('access_window.enabled_course_ids'). Платежи не
 * трогаются — окно лишь сужает/открывает доступ по дате (H4456).
 * Директная выдача окна без покупки бессмысленна (нет ключей — нечего
 * открывать), поэтому не-купившему курс в пикере не показывается.
 */
class CourseAccessWindowsRelationManager extends RelationManager
{
    protected static string $relationship = 'courseAccessWindows';

    protected static ?string $title = 'Окна доступа';

    protected static ?string $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->state(fn (CourseAccessWindow $r): string => match (true) {
                        $r->ends_at !== null && $r->ends_at->isPast() => 'expired',
                        $r->ends_at === null => 'forever',
                        default => 'active',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'активно',
                        'expired' => 'истекло',
                        'forever' => 'вечное (исключение)',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'expired' => 'warning',
                        'forever' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('До')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('бессрочно (исключение)'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Кто выдал')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('grant_window')
                    ->label('Выдать окно')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->visible(fn (): bool => RoleGate::any(Roles::ADMIN, Roles::SUPER_ADMIN, Roles::MANAGER)
                        && self::scopeCourses($this->getOwnerRecord())->isNotEmpty())
                    ->modalHeading('Окно доступа к записям')
                    ->modalDescription('Доступ к записям курса выдаётся окном: пакет 30 дней по запросу студента, продления — ещё по 30. Окно не трогает платежи. Вечное окно — только по прямому слову MG.')
                    ->form([
                        Forms\Components\Select::make('course_id')
                            ->label('Курс (только купленные студентом)')
                            ->options(fn (): array => self::scopeCourses($this->getOwnerRecord())
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('До (включительно)')
                            ->native(false)
                            ->seconds(false)
                            ->default(fn () => now()->addDays((int) config('access_window.default_window_days', 30)))
                            ->minDate(now())
                            ->helperText('Пакет 30 дней подставлен автоматически. Вечное окно — галочка ниже, только по слову MG.'),
                        Forms\Components\Toggle::make('forever')
                            ->label('Вечное окно (именное исключение)')
                            ->helperText('Только по прямому слову MG. Отметьте reason с датой рулинга.'),
                        Forms\Components\Textarea::make('reason')
                            ->label('Причина (для аудита)')
                            ->rows(2)
                            ->required()
                            ->placeholder('Например: «по запросу студента 09-09, пакет 30 дней».'),
                    ])
                    ->action(function (array $data): void {
                        $student = $this->getOwnerRecord();

                        $result = CourseAccessWindow::grantInScope(
                            $student,
                            (int) ($data['course_id'] ?? 0),
                            (bool) ($data['forever'] ?? false)
                                ? null
                                : (! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : null),
                            ! empty($data['reason']) ? (string) $data['reason'] : null,
                            auth()->id(),
                        );

                        if (! $result['ok']) {
                            Notification::make()
                                ->title($result['error'] === 'course_not_purchased'
                                    ? 'Курс не куплен студентом'
                                    : 'Курс вне скоупа окон')
                                ->body($result['error'] === 'course_not_purchased'
                                    ? 'Окно выдаётся только на купленный курс. Не-купившему — ссылка на покупку.'
                                    : 'Скоуп задаётся config(access_window.enabled_course_ids) — сейчас только курсы Парибока.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $window = $result['window'];

                        Notification::make()
                            ->title($window->isActive() ? 'Окно выдано' : 'Окно в прошлом — доступ закрыт')
                            ->body($window->ends_at?->format('d.m.Y H:i') ?? 'бессрочно (именное исключение)')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('clear_window')
                    ->label('Снять')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => RoleGate::any(Roles::ADMIN, Roles::SUPER_ADMIN, Roles::MANAGER))
                    ->requiresConfirmation()
                    ->modalDescription('Окно будет удалено: студент вернётся к прежнему состоянию (реальные платежи снова открывают курс). Действие мгновенно и обратимо — окно можно выдать заново.')
                    ->action(function (CourseAccessWindow $r): void {
                        CourseAccessWindow::clearFor((int) $r->user_id, (int) $r->course_id);
                        Notification::make()->title('Окно снято')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Окон доступа не выдавалось')
            ->emptyStateDescription('Выдайте окно, чтобы открыть студенту записи курса из скоупа на 30 дней (или закрыть доступ раньше вечного срока).');
    }

    /**
     * Курсы, на которые этому студенту можно выдать окно: пересечение
     * скоупа (config) и реально купленных (paid real).
     *
     * @return Collection<int, Course>
     */
    private static function scopeCourses(User $student): Collection
    {
        $ids = self::scopeCourseIds($student);

        return $ids === []
            ? collect()
            : Course::query()->whereIn('id', $ids)->orderBy('title')->get();
    }

    /**
     * @return list<int>
     */
    private static function scopeCourseIds(User $student): array
    {
        $scope = array_map(intval(...), (array) config('access_window.enabled_course_ids', []));

        if ($scope === []) {
            return [];
        }

        return Payment::query()
            ->where('user_id', $student->id)
            ->whereIn('course_id', $scope)
            ->paid()
            ->real()
            ->where('amount', '>', 0)
            ->distinct()
            ->pluck('course_id')
            ->map(intval(...))
            ->all();
    }
}
