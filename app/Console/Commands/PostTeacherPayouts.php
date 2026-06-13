<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeacherPayout;
use App\Services\TeacherPayoutPoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Проводит в «Финансах» уже существующие выплаты преподам (transaction-зеркало
 * salary_payout), у которых ещё нет привязанной транзакции. По умолчанию —
 * dry-run; запись только с --apply. Идемпотентно: повторный прогон не дублирует.
 */
class PostTeacherPayouts extends Command
{
    protected $signature = 'salary:post-payouts {--apply : Реально создать транзакции (без флага — только показать)}';

    protected $description = 'Создаёт транзакции «Выплата ЗП» в Финансах для ранее записанных выплат без привязки.';

    public function handle(TeacherPayoutPoster $poster): int
    {
        $apply = (bool) $this->option('apply');

        $payouts = TeacherPayout::query()
            ->whereNull('payment_id')
            ->with('teacher')
            ->get();

        $candidates = $payouts->filter(fn (TeacherPayout $p) => (float) $p->amount != 0.0);
        $skippedZero = $payouts->count() - $candidates->count();

        $this->info('Выплат без транзакции: '.$payouts->count()
            .' · к проведению: '.$candidates->count()
            .($skippedZero > 0 ? " · пропущено (нулевая сумма): {$skippedZero}" : ''));

        if ($candidates->isEmpty()) {
            $this->info('Нечего проводить.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->table(
                ['ID выплаты', 'Преподаватель', 'Период', 'Сумма', 'Курс'],
                $candidates->take(50)->map(fn (TeacherPayout $p) => [
                    $p->id,
                    $p->teacher?->name ?? ('#'.$p->teacher_id),
                    $p->period_month ?? '—',
                    number_format((float) $p->amount, 0, '.', ' ').' ₽',
                    $p->course_id ?? 'техн.',
                ])->all(),
            );
            $this->warn('Это предпросмотр (dry-run). Запуск с --apply создаст транзакции.');

            return self::SUCCESS;
        }

        $created = 0;
        DB::transaction(function () use ($candidates, $poster, &$created) {
            foreach ($candidates as $payout) {
                if ($poster->post($payout)) {
                    $created++;
                }
            }
        });

        $this->info("Готово. Создано транзакций: {$created}.");

        return self::SUCCESS;
    }
}
