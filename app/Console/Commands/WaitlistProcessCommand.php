<?php

namespace App\Console\Commands;

use App\Models\CourseWaitlistItem;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Список ожидания (MG 31-08-2026, волна 3): движок порогов и лестницы переносов.
 *
 * Ежедневный прогон делает три вещи по каждой активной строке:
 *
 *  1. Порог голосов достигнут (votes >= min_payers), статус collecting →
 *     payment_open. Открывается оплата только когда прогноз (голоса × k=0.5,
 *     потолок 60 % исторического набора) достигает минимума — не жечь
 *     доверие преждевременным сбором денег при слабой конверсии.
 *
 *  2. payment_open + нужное число ОПЛАТ (block payments, paid) к плановой
 *     дате → scheduled: создаются Schedule-потоки не раньше earliest_start_at.
 *
 *  3. Дедлайн (за 7 дней до планового старта) прошёл, оплат < min_payers →
 *     перенос по лестнице: попытка «до конца октября» → январь (grammar) /
 *     март (other); январь/март не начались → июль; июль не начался →
 *     сентябрь следующего года. Цикл 4 попытки из года в год, максимум
 *     4 года (start_attempts ≤ 16), затем closed.
 */
class WaitlistProcessCommand extends Command
{
    protected $signature = 'waitlist:process
        {--dry-run : показать, что будет сделано, не меняя БД}';

    protected $description = 'Двигать строки списка ожидания: пороги голосов, оплаты, лестница переносов (MG 31-08-2026)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $items = CourseWaitlistItem::query()
            ->whereIn('status', [
                CourseWaitlistItem::STATUS_COLLECTING,
                CourseWaitlistItem::STATUS_PAYMENT_OPEN,
                CourseWaitlistItem::STATUS_PAYMENT_DEADLINE_PASSED,
            ])
            ->get();

        $actions = [];

        foreach ($items as $item) {
            $votes = $item->votesCount();

            if ($item->status === CourseWaitlistItem::STATUS_COLLECTING
                && $item->hasThreshold()
                && $this->forecastMeetsMinimum($item)) {
                $actions[] = "[{$item->slug}] collecting → payment_open (голосов {$votes}/{$item->min_payers})";
                if (! $dryRun) {
                    $item->update(['status' => CourseWaitlistItem::STATUS_PAYMENT_OPEN]);
                }

                continue;
            }

            $paid = $this->countPaidPayments($item);

            if ($item->status === CourseWaitlistItem::STATUS_PAYMENT_OPEN
                && $paid >= $item->min_payers) {
                $actions[] = "[{$item->slug}] payment_open → scheduled (оплат {$paid}/{$item->min_payers})";
                if (! $dryRun) {
                    $item->update(['status' => CourseWaitlistItem::STATUS_SCHEDULED]);
                    // Живые встречи создаст куратор в Filament (курс уже привязан/создан);
                    // waitlist сам Schedule-строки не генерирует — не трогаем учебный контур.
                }

                continue;
            }

            // Лестница переносов: дедлайн = planned_start - 7 дней прошёл.
            $deadline = $this->paymentDeadline($item);
            if ($item->status === CourseWaitlistItem::STATUS_PAYMENT_OPEN
                && $deadline !== null
                && now()->startOfDay()->greaterThanOrEqualTo($deadline)
                && $paid < $item->min_payers) {
                $next = $this->nextRung($item, $dryRun);
                if ($next !== null) {
                    $actions[] = "[{$item->slug}] оплат {$paid}/{$item->min_payers} → перенос, попытка {$item->start_attempts}, план {$next->format('Y-m-d')}";
                }
            }
        }

        $this->info($dryRun ? 'dry-run' : 'executed');
        foreach ($actions as $a) {
            $this->line($a);
        }
        if ($actions === []) {
            $this->line('без изменений');
        }

        return self::SUCCESS;
    }

    private const MAX_ATTEMPTS = 16; // 4 попытки × 4 года

    private function forecastMeetsMinimum(CourseWaitlistItem $item): bool
    {
        $f = $item->forecastPayments();

        return $f['high'] >= $item->min_payers;
    }

    private function countPaidPayments(CourseWaitlistItem $item): int
    {
        if ($item->course_id === null) {
            return 0;
        }

        return Payment::query()
            ->where('course_id', $item->course_id)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where('amount', '>', 0)
            ->count();
    }

    private function paymentDeadline(CourseWaitlistItem $item): ?Carbon
    {
        $start = $item->planned_start_at ?? $item->earliest_start_at;

        return $start ? Carbon::parse($start)->subDays(7)->startOfDay() : null;
    }

    /**
     * Лестница: октябрь(до конца) → янв/март → июль → сентябрь след. года;
     * цикл 4 попытки из года в год, максимум 4 года; earliest_start_at уважается.
     *
     * @return Carbon|null новая плановая дата (null = closed)
     */
    private function nextRung(CourseWaitlistItem $item, bool $dryRun): ?Carbon
    {
        $attempts = $item->start_attempts + 1;

        if ($attempts > self::MAX_ATTEMPTS) {
            if (! $dryRun) {
                $item->update(['status' => CourseWaitlistItem::STATUS_CLOSED]);
            }
            $this->line("[{$item->slug}] попытки исчерпаны (4 года) → закрыто");

            return null;
        }

        $earliest = $item->earliest_start_at
            ? Carbon::parse($item->earliest_start_at)
            : null;

        $base = $item->planned_start_at
            ? Carbon::parse($item->planned_start_at)
            : ($earliest ?? now()->startOfDay());

        $candidate = $this->nextRungDate($base, $item->kind);
        // Уважаем earliest: пока кандидат раньше — поднимаемся по лестнице.
        while ($earliest !== null && $candidate->lessThan($earliest)) {
            $candidate = $this->nextRungDate($candidate, $item->kind);
        }

        if (! $dryRun) {
            $item->update([
                'status' => CourseWaitlistItem::STATUS_POSTPONED,
                'start_attempts' => $attempts,
                'planned_start_at' => $candidate->toDateString(),
            ]);
        }

        return $candidate;
    }

    /**
     * Следующая попытка по лестнице от базовой даты:
     *   попытка не началась до конца октября → grammar: январь след. года,
     *   other: март след. года;
     *   январь/март не начались → июль того же года;
     *   июль не начался → сентябрь того же года;
     *   сентябрь–декабрь не начались → январь следующего года (новый цикл).
     */
    private function nextRungDate(Carbon $from, string $kind): Carbon
    {
        $y = (int) $from->format('Y');
        $m = (int) $from->format('n');

        return match (true) {
            // январь/март не начались → июль того же года
            $m <= 3 => Carbon::create($y, 7, 1, 0, 0, 0),
            // июль не начался → сентябрь того же года
            $m >= 4 && $m <= 8 => Carbon::create($y, 9, 1, 0, 0, 0),
            // сентябрь не начался → январь следующего года (новый цикл)
            $m === 9 => Carbon::create($y + 1, 1, 1, 0, 0, 0),
            // октябрь–декабрь: октябрьская попытка провалилась → янв/март след. года
            default => Carbon::create($y + 1, $kind === 'grammar' ? 1 : 3, 1, 0, 0, 0),
        };
    }
}
