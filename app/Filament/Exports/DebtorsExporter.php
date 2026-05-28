<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorsReport;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DebtorsExporter extends Exporter
{
    protected static ?string $model = User::class;

    /** @var array<string, CourseBlock>|null */
    private static ?array $blocksLookup = null;

    /** @var array<int, string>|null */
    private static ?array $courseTitles = null;

    /** @var array<string, array{amount:?float, missing:int}> */
    private static array $debtAmountCache = [];

    /** @var array<string, PaymentPromise|null> */
    private static array $promiseCache = [];

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID студента'),
            ExportColumn::make('name')->label('Имя'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('phone')->label('Телефон'),

            ExportColumn::make('course_title')
                ->label('Курс')
                ->state(fn (User $r): string => self::courseTitles()[$r->course_id] ?? ''),

            ExportColumn::make('ref_block_number')->label('№ блока'),

            ExportColumn::make('block_starts_at')
                ->label('Начало блока')
                ->state(fn (User $r): ?string => self::block($r)?->starts_at?->format('d.m.Y')),

            ExportColumn::make('block_ends_at')
                ->label('Конец блока')
                ->state(fn (User $r): ?string => self::block($r)?->ends_at?->format('d.m.Y')),

            ExportColumn::make('debt_type')
                ->label('Тип долга')
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'not_renewed' => 'Не продлил',
                    'no_payment' => 'Без оплат',
                    default => '',
                }),

            ExportColumn::make('last_payment_at')
                ->label('Последний платёж: дата')
                ->state(fn (User $r): ?string => self::lastPayment($r)?->created_at?->format('d.m.Y')),

            ExportColumn::make('last_payment_amount')
                ->label('Последний платёж: сумма')
                ->state(fn (User $r): ?string => self::lastPayment($r)?->amount),

            ExportColumn::make('debt_amount')
                ->label('Сумма долга, ₽')
                ->state(function (User $r): ?string {
                    $info = self::debtAmountInfo($r);
                    if ($info['amount'] === null) {
                        return null;
                    }

                    return ($info['missing'] > 0 ? '≈ ' : '').number_format($info['amount'], 2, '.', '');
                }),

            ExportColumn::make('debt_amount_approximate')
                ->label('Сумма приблизительная')
                ->state(fn (User $r): string => self::debtAmountInfo($r)['missing'] > 0 ? 'да' : ''),

            ExportColumn::make('promise_date')
                ->label('Обещание: дата')
                ->state(fn (User $r): ?string => self::activePromise($r)?->promised_at?->format('d.m.Y')),

            ExportColumn::make('promise_amount')
                ->label('Обещание: сумма')
                ->state(fn (User $r): ?string => self::activePromise($r)?->amount),

            ExportColumn::make('promise_note')
                ->label('Обещание: комментарий')
                ->state(fn (User $r): ?string => self::activePromise($r)?->note),

            ExportColumn::make('has_telegram')
                ->label('TG')
                ->state(fn (User $r): string => $r->telegram_id ? 'да' : ''),

            ExportColumn::make('has_vk')
                ->label('VK')
                ->state(fn (User $r): string => $r->vk_id ? 'да' : ''),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт должников завершён, обработано '.number_format($export->successful_rows).' записей.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать '.number_format($failed).' строк.';
        }

        return $body;
    }

    private static function block(User $r): ?CourseBlock
    {
        $lookup = self::blocksLookup();

        return $lookup[$r->course_id.':'.$r->ref_block_number] ?? null;
    }

    private static function lastPayment(User $r): ?Payment
    {
        if (! $r->last_payment_id) {
            return null;
        }

        return Payment::find($r->last_payment_id);
    }

    /**
     * @return array<string, CourseBlock>
     */
    private static function blocksLookup(): array
    {
        return self::$blocksLookup ??= app(DebtorsReport::class)->blocksLookup();
    }

    /**
     * @return array<int, string>
     */
    private static function courseTitles(): array
    {
        return self::$courseTitles ??= app(DebtorsReport::class)->courseTitles();
    }

    /**
     * @return array{amount:?float, missing:int}
     */
    private static function debtAmountInfo(User $r): array
    {
        $key = $r->id.':'.$r->course_id;
        if (isset(self::$debtAmountCache[$key])) {
            return self::$debtAmountCache[$key];
        }

        $blocks = \App\Filament\Pages\Debtors::debtBlocks(
            (int) $r->id,
            (int) $r->course_id,
            (int) $r->ref_block_number,
        );
        $info = app(DebtorsReport::class)->computeDebtAmount($r, (int) $r->course_id, $blocks);

        return self::$debtAmountCache[$key] = [
            'amount' => $info['amount'],
            'missing' => $info['missing_tariffs'],
        ];
    }

    private static function activePromise(User $r): ?PaymentPromise
    {
        $key = $r->id.':'.$r->course_id;
        if (array_key_exists($key, self::$promiseCache)) {
            return self::$promiseCache[$key];
        }

        $row = PaymentPromise::query()
            ->forPair((int) $r->id, (int) $r->course_id)
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->orderByDesc('promised_at')
            ->first();

        return self::$promiseCache[$key] = $row;
    }
}
