<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Group;
use App\Services\Discipline\DisciplineScore;
use App\Services\DisciplineScoreService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Advisory-виджет «Дисциплина по группам» (см. docs/discipline-score-spec.md).
 * Скор считается on-demand через DisciplineScoreService::forGroup() — не
 * персистится, ни на что автоматически не влияет.
 */
class DisciplineByGroupWidget extends BaseWidget
{
    protected static ?string $heading = 'Дисциплина по группам';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array{score: ?int, tier: ?string, member_count: int}> */
    private array $resultCache = [];

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $query = Group::query();

                // Преподаватель видит только свои группы (свои курсы, основной
                // или со-препод) — read-only, тот же принцип, что и в
                // TeacherSalaryService/Course::scopeForTeacher.
                if (! RoleGate::adminOnly()) {
                    $teacherId = auth()->user()?->teacher_id;

                    $query->whereHas('courses', fn ($q) => $q->forTeacher($teacherId));
                }

                return $query;
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Группа')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('member_count')
                    ->label('Активных участников')
                    ->state(fn (Group $r): int => $this->resultFor($r)['member_count'])
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('discipline_score')
                    ->label('Средний скор')
                    ->state(fn (Group $r): string => $this->resultFor($r)['score'] !== null
                        ? (string) $this->resultFor($r)['score']
                        : '—')
                    ->alignRight()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('discipline_tier')
                    ->label('Тир')
                    ->badge()
                    ->state(fn (Group $r): string => $this->tierLabel($r))
                    ->color(fn (Group $r): string => $this->tierColor($r)),
            ])
            ->emptyStateHeading('Групп не найдено');
    }

    /**
     * @return array{score: ?int, tier: ?string, member_count: int}
     */
    private function resultFor(Group $group): array
    {
        return $this->resultCache[$group->id] ??= app(DisciplineScoreService::class)->forGroup($group);
    }

    private function tierLabel(Group $group): string
    {
        $tier = $this->resultFor($group)['tier'];
        if ($tier === null) {
            return '—';
        }

        return (new DisciplineScore(0, $tier, []))->label();
    }

    private function tierColor(Group $group): string
    {
        $tier = $this->resultFor($group)['tier'];
        if ($tier === null) {
            return 'gray';
        }

        return (new DisciplineScore(0, $tier, []))->color();
    }
}
