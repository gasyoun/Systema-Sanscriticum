<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Reactivation\WinBackSender;
use App\Services\ReactivationReport;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Реактивация «уснувшей» базы — ТОЛЬКО ЧТЕНИЕ. Список кандидатов (не продлил /
 * без оплат) поверх {@see ReactivationReport} с подсказанным win/loss-шаблоном
 * `/реактивация-*`. Страница ничего не рассылает: выгрузку делает куратор
 * вручную по подсказке (ручной процесс владельца), без давления.
 */
class Reactivation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Реактивация';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 21;

    protected static string $view = 'filament.pages.reactivation';

    private ?ReactivationReport $reportMemo = null;

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    private function report(): ReactivationReport
    {
        return $this->reportMemo ??= app(ReactivationReport::class);
    }

    public function table(Table $table): Table
    {
        $courseTitles = $this->report()->debtors()->courseTitles();
        $blocksLookup = $this->report()->debtors()->blocksLookup();

        return $table
            ->query(fn () => $this->report()->query())
            ->recordTitleAttribute('name')
            ->recordTitle(fn (Model $r) => $r->name ?: $r->email)
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
                        foreach ([
                            'telegram_id' => 'TG',
                            'vk_id' => 'VK',
                            'max_user_id' => 'Max',
                            'phone' => '📞',
                        ] as $field => $tag) {
                            if (! empty($r->{$field})) {
                                $bits[] = $tag;
                            }
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
                    ->width('30%')
                    ->searchable(['name', 'email', 'phone'])
                    ->url(fn (Model $r): string => UserResource::getUrl('edit', ['record' => $r->getKey()]))
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('ref_block_number')
                    ->label('Блок')
                    ->formatStateUsing(fn ($state): string => '№'.$state)
                    ->description(function (Model $r) use ($blocksLookup): ?string {
                        $block = $blocksLookup[$r->course_id.':'.$r->ref_block_number] ?? null;
                        if ($block instanceof CourseBlock && $block->starts_at && $block->ends_at) {
                            return $block->starts_at->format('d.m').' – '.$block->ends_at->format('d.m.Y');
                        }

                        return null;
                    })
                    ->sortable()
                    ->alignCenter()
                    ->width('15%'),

                Tables\Columns\TextColumn::make('debt_type')
                    ->label('Причина')
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
                    })
                    ->width('17%'),

                Tables\Columns\TextColumn::make('reactivation_suggestion')
                    ->label('Подсказка куратору')
                    ->state(fn (Model $r): string => $this->report()->suggestTemplate($r)->label())
                    ->description(fn (Model $r): string => $this->report()->suggestTemplate($r)->command())
                    ->tooltip(fn (Model $r): string => $this->report()->suggestTemplate($r)->whenToUse())
                    ->badge()
                    ->color('info')
                    ->wrap()
                    ->width('38%'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('debt_type')
                    ->label('Причина')
                    ->options([
                        'not_renewed' => 'Не продлил',
                        'no_payment' => 'Без оплат',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->where('d.debt_type', $data['value']);
                        }
                    }),
            ]);
    }

    /**
     * Кратко — для intro-блока в blade: сколько кандидатов и разбивка по причине.
     *
     * @return array{total:int, not_renewed:int, no_payment:int}
     */
    public function summary(): array
    {
        $rows = $this->report()->query()->get(['users.id', 'd.debt_type']);

        return [
            'total' => $rows->count(),
            'not_renewed' => $rows->where('debt_type', 'not_renewed')->count(),
            'no_payment' => $rows->where('debt_type', 'no_payment')->count(),
        ];
    }
}
