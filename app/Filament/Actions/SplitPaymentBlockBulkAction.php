<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\Course;
use App\Models\Group;
use App\Services\Payments\PaymentBlockHalfSplitter;
use App\Support\RoleGate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * «Разбить оплату блока на другую группу» — массовое действие списка «Студенты».
 *
 * Для случая, когда группа-курс A распалась посреди блока, а студенты доучиваются
 * в курсе-когорте B: «Перенести в группу» тут не помогает — оплата привязана к
 * КУРСУ, а не к группе, и платёж курса A уроки курса B не открывает. Действие
 * делит оплаченный блок на два платежа-половины (PaymentBlockHalfSplitter).
 *
 * По умолчанию — сухой прогон: показывает, что будет сделано, и ничего не пишет.
 * Применение — отдельная галочка и только при features.payment_block_half_split.
 */
final class SplitPaymentBlockBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('split_block_to_group')
            ->label('Разбить оплату блока на другую группу')
            ->icon('heroicon-o-scissors')
            ->color('danger')
            ->visible(fn (): bool => RoleGate::adminOnly())
            ->modalHeading('Разбить оплату блока между двумя группами')
            ->modalDescription('Оплаченный блок целиком станет двумя половинами: 1-я остаётся на курсе-источнике, 2-я переезжает на курс целевой группы, и у студентов откроются уроки обеих половин. Студент добавляется в целевую группу, а в прежней получает пометку «вышел» (строка остаётся: по ней считается зарплата преподавателя). Сначала сделайте сухой прогон.')
            ->modalSubmitActionLabel('Выполнить')
            ->form([
                Select::make('from_course_id')
                    ->label('Курс-источник (где оплачен блок)')
                    ->options(fn (): array => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('target_group_id')
                    ->label('Целевая группа')
                    ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->helperText('Курс-цель берётся из этой группы.'),
                TextInput::make('block')
                    ->label('Номер блока')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(),
                TextInput::make('percent')
                    ->label('Доля, переносимая на курс-цель, %')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(50)
                    ->required()
                    ->helperText('50 — если из 4 занятий блока 2 прошли в старой группе.'),
                Toggle::make('apply')
                    ->label('Применить (иначе — только сухой прогон)')
                    ->default(false)
                    ->disabled(fn (): bool => ! PaymentBlockHalfSplitter::enabled())
                    ->helperText(fn (): ?string => PaymentBlockHalfSplitter::enabled()
                        ? 'Изменяет платежи: правка исходного и создание нового. Запускайте после сухого прогона.'
                        : 'Применение выключено (флаг payment_block_half_split). Доступен только сухой прогон.'),
            ])
            ->action(function (Collection $records, array $data): void {
                $from = Course::query()->find($data['from_course_id'] ?? null);
                $group = Group::query()->with('courses')->find($data['target_group_id'] ?? null);
                $to = $group?->courses->first();

                if (! $from || ! $group || ! $to) {
                    Notification::make()
                        ->title('Не определён курс')
                        ->body('Укажите курс-источник и целевую группу, привязанную к курсу.')
                        ->danger()
                        ->send();

                    return;
                }

                $splitter = app(PaymentBlockHalfSplitter::class);
                $block = (int) $data['block'];
                $plan = $splitter->plan($records, $from, $to, $block, (float) $data['percent']);

                $applied = false;
                if (! empty($data['apply']) && $plan['blocking'] === [] && PaymentBlockHalfSplitter::enabled()) {
                    $plan = $splitter->apply($plan, $from, $to, $block);
                    $applied = true;
                }

                self::report($plan, $from, $to, $group, $block, $applied);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param  array{blocking: list<string>, warnings: list<string>, rows: list<array<string, mixed>>}  $plan
     */
    private static function report(array $plan, Course $from, Course $to, Group $group, int $block, bool $applied): void
    {
        $rows = collect($plan['rows']);
        $count = fn (string $status): int => $rows->where('status', $status)->count();

        $lines = [];
        $lines[] = e("«{$from->title}» → «{$to->title}» (группа «{$group->name}»), блок {$block}");

        foreach ($plan['blocking'] as $problem) {
            $lines[] = '⛔ '.e($problem);
        }
        foreach ($plan['warnings'] as $warning) {
            $lines[] = '⚠ '.e($warning);
        }

        if ($plan['blocking'] === []) {
            $moved = (float) $rows->whereIn('status', [PaymentBlockHalfSplitter::STATUS_READY, PaymentBlockHalfSplitter::STATUS_DONE])->sum('amount_moved');
            $before = (float) $rows->whereIn('status', [PaymentBlockHalfSplitter::STATUS_READY, PaymentBlockHalfSplitter::STATUS_DONE])->sum('amount_before');

            $lines[] = $applied
                ? sprintf('Выполнено: %d, не применено: %d, отказов: %d, пропущено: %d.', $count(PaymentBlockHalfSplitter::STATUS_DONE), $count(PaymentBlockHalfSplitter::STATUS_FAILED) + $count(PaymentBlockHalfSplitter::STATUS_READY), $count(PaymentBlockHalfSplitter::STATUS_REFUSED), $count(PaymentBlockHalfSplitter::STATUS_SKIPPED))
                : sprintf('Сухой прогон: будет разбито %d, отказов: %d, пропущено: %d.', $count(PaymentBlockHalfSplitter::STATUS_READY), $count(PaymentBlockHalfSplitter::STATUS_REFUSED), $count(PaymentBlockHalfSplitter::STATUS_SKIPPED));
            $lines[] = sprintf('Перейдёт на курс-цель: %s ₽ из %s ₽.', number_format($moved, 2, ',', ' '), number_format($before, 2, ',', ' '));

            $problems = $rows->reject(fn (array $r): bool => in_array($r['status'], [PaymentBlockHalfSplitter::STATUS_READY, PaymentBlockHalfSplitter::STATUS_DONE], true));
            foreach ($problems->take(12) as $row) {
                $lines[] = '• '.e((string) $row['user_name']).' — '.e((string) $row['reason']);
            }
            if ($problems->count() > 12) {
                $lines[] = '…и ещё '.($problems->count() - 12).'.';
            }
        }

        $notification = Notification::make()
            ->title($applied ? 'Оплата блока разбита' : 'Сухой прогон: ничего не записано')
            ->body(implode('<br>', $lines))
            ->persistent();

        ($plan['blocking'] !== [] ? $notification->danger() : ($applied ? $notification->success() : $notification->info()))->send();
    }
}
