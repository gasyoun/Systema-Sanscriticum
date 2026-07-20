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
use Carbon\CarbonInterface;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
                            $dt = $r->last_activity_at instanceof CarbonInterface
                                ? $r->last_activity_at
                                : Carbon::parse($r->last_activity_at);
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
            ])
            ->actions([
                $this->sendTemplateAction(),
            ])
            ->bulkActions([
                $this->sendTemplateBulkAction(),
            ]);
    }

    /**
     * Строчное действие «Отправить шаблон» (за флагом crm_cockpit, H221).
     * Пока read-only-режим по умолчанию сохранён: без флага действие скрыто.
     */
    private function sendTemplateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('send_template')
            ->label('Отправить шаблон')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Model $r): bool => config('features.crm_cockpit')
                && ($this->hasAnyChannel($r)))
            ->modalHeading(fn (Model $r): string => 'Реактивация — '.($r->name ?: $r->email))
            ->form([
                Forms\Components\Select::make('template_id')
                    ->label('Шаблон реактивации')
                    ->options(fn (): array => $this->reactivationTemplateOptions())
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('Категория «Реактивация» из библиотеки шаблонов.'),
                Forms\Components\Placeholder::make('preview')
                    ->label('Предпросмотр')
                    ->content(function (Forms\Get $get, $livewire): string {
                        $tpl = ($id = $get('template_id')) ? MessageTemplate::find($id) : null;
                        if (! $tpl) {
                            return 'Выберите шаблон, чтобы увидеть текст.';
                        }
                        $record = method_exists($livewire, 'getMountedTableActionRecord')
                            ? $livewire->getMountedTableActionRecord() : null;
                        $user = $record ? User::find($record->id) : null;
                        if (! $user) {
                            return $tpl->body;
                        }
                        $course = $record?->course_id ? Course::find($record->course_id) : null;

                        return $tpl->render($user, $course, $this->blockOf($record));
                    }),
            ])
            ->action(function (Model $r, array $data): void {
                $tpl = MessageTemplate::find($data['template_id'] ?? null);
                if (! $tpl) {
                    Notification::make()->title('Шаблон не найден')->danger()->send();

                    return;
                }

                $res = app(WinBackSender::class)->send(
                    (int) $r->id,
                    (int) $r->course_id,
                    $this->blockOf($r),
                    $tpl,
                    auth()->user(),
                );

                $this->notifyResult($res);
            });
    }

    /** Массовая отправка одного шаблона по выбранным кандидатам. */
    private function sendTemplateBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('send_template_bulk')
            ->label('Отправить шаблон')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (): bool => (bool) config('features.crm_cockpit'))
            ->form([
                Forms\Components\Select::make('template_id')
                    ->label('Шаблон реактивации')
                    ->options(fn (): array => $this->reactivationTemplateOptions())
                    ->required()
                    ->native(false),
            ])
            ->action(function (Collection $records, array $data): void {
                $tpl = MessageTemplate::find($data['template_id'] ?? null);
                if (! $tpl) {
                    Notification::make()->title('Шаблон не найден')->danger()->send();

                    return;
                }

                $sender = app(WinBackSender::class);
                $sent = 0;
                $skipped = 0;
                foreach ($records as $r) {
                    $res = $sender->send(
                        (int) $r->id,
                        (int) $r->course_id,
                        $this->blockOf($r),
                        $tpl,
                        auth()->user(),
                    );
                    $res['status'] === 'sent' ? $sent++ : $skipped++;
                }

                Notification::make()
                    ->title("Отправлено: {$sent}. Пропущено (дедуп/нет каналов): {$skipped}.")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private function hasAnyChannel(Model $r): bool
    {
        return ! empty($r->telegram_id)
            || ! empty($r->vk_id)
            || filter_var($r->email, FILTER_VALIDATE_EMAIL);
    }

    private function blockOf(?Model $r): ?int
    {
        return $r && $r->ref_block_number !== null ? (int) $r->ref_block_number : null;
    }

    /** @return array<int,string> id => title активных шаблонов реактивации */
    private function reactivationTemplateOptions(): array
    {
        return MessageTemplate::query()
            ->forCategory(MessageTemplate::CATEGORY_REACTIVATION)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @param  array{status:string, channels:array}  $res */
    private function notifyResult(array $res): void
    {
        match ($res['status']) {
            'sent' => Notification::make()
                ->title('Отправлено: '.implode(', ', $res['channels']))
                ->success()->send(),
            'skipped' => Notification::make()
                ->title('Пропущено: уже писали за последние 24 ч')
                ->warning()->send(),
            'no_channels' => Notification::make()
                ->title('Нет доступных каналов для отправки')
                ->danger()->send(),
            default => Notification::make()
                ->title('Студент не найден')
                ->danger()->send(),
        };
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
