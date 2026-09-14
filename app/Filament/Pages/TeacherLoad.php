<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\TeacherLoadByDirectionChart;
use App\Models\Category;
use App\Models\Teacher;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Отчёт «Нагрузка преподавателей» (H1426, wave 1a): сколько групп ведёт каждый
 * преподаватель, с разбивкой по направлению. Направление — производная величина
 * (Group::directions(), не поле), см. ARCHITECTURE_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md.
 */
class TeacherLoad extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Нагрузка преподавателей';

    protected static ?string $title = 'Нагрузка преподавателей';

    public function getSubheading(): ?string
    {
        return 'Сейчас: все группы, привязанные к курсам преподавателя. Без отсечки по месяцу и без учебного года — это не «нагрузка за август».';
    }

    protected static ?string $slug = 'teacher-load';

    protected static string $view = 'filament.pages.teacher-load';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getHeaderWidgets(): array
    {
        return [TeacherLoadByDirectionChart::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Преподаватель')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('groups_led_count')
                    ->label('Всего групп')
                    ->getStateUsing(fn (Teacher $record) => $record->groupsLed()->count()),

                Tables\Columns\TextColumn::make('directions')
                    ->label('Направления')
                    ->badge()
                    ->getStateUsing(
                        fn (Teacher $record) => $record->groupsLed()
                            ->flatMap(fn ($group) => $group->directions())
                            ->unique('id')
                            ->pluck('name')
                            ->all()
                    ),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->label('Направление')
                    ->options(fn () => Category::orderBy('sort_order')->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data) {
                        $categoryId = $data['value'] ?? null;

                        if (! $categoryId) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($categoryId) {
                            $q->whereHas(
                                'courses',
                                fn (Builder $q2) => $q2->whereHas(
                                    'categories',
                                    fn (Builder $q3) => $q3->where('categories.id', $categoryId)
                                )
                            )->orWhereHas(
                                'coTaughtCourses',
                                fn (Builder $q2) => $q2->whereHas(
                                    'categories',
                                    fn (Builder $q3) => $q3->where('categories.id', $categoryId)
                                )
                            );
                        });
                    }),
            ]);
    }

    /** Админ видит всех преподавателей; преподаватель — только свою строку. */
    private function baseQuery(): Builder
    {
        $query = Teacher::query();
        $user = auth()->user();

        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            $query->whereKey($user->teacher_id);
        }

        return $query;
    }
}
