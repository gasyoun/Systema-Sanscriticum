<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeacherPayout;
use App\Models\TeacherPayoutPackage;
use App\Services\Payout\CompensationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H5444 (P2): сверка нового пакетного расчёта со старым `teacher_payouts`.
 *
 * ТОЛЬКО ЧТЕНИЕ. Ничего не создаёт, не меняет и не переключает: историю
 * выплат не переписываем, читателей не двигаем (D13/P4). Команда работает и
 * при выключенном флаге — именно она решает, когда его можно включить.
 *
 * Классы строк:
 *  - `tie`               — легаси-строка сошлась с пакетом до копейки;
 *  - `delta`             — есть пакет, но итог расходится (требует объяснения);
 *  - `missing_package`   — легаси-строка без пакета (ещё не перенесена);
 *  - `unresolved_rate`   — нет датированного назначения компенсации на дату
 *                          выплаты; ставку НЕ выводим (D15/D17). Именно сюда
 *                          попадает пятёрка H5250 до рулинга MG;
 *  - `orphan_package`    — пакет без легаси-строки (новая выплата).
 *
 * H5250 (офлайн-выплаты, ставки спорны: Уша 20% vs 30% в базе, Горностаева
 * со-преподавание ~9%, Толчельников implied 42–58%) остаётся ИМЕННО
 * исключением `unresolved_rate` до документированного источника ставки —
 * подстановка `courses.salary_value` здесь запрещена по построению.
 */
class PayoutPackageCompareCommand extends Command
{
    protected $signature = 'money:payout-package-compare
                            {--since= : рассматривать выплаты с этой даты (Y-m-d)}
                            {--teacher= : ограничить одним teacher_id}
                            {--json : машинный вывод}';

    protected $description = 'H5444: сверить расчётные пакеты выплат с легаси teacher_payouts (только чтение)';

    public function handle(CompensationResolver $resolver): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;

        $legacy = TeacherPayout::query()
            ->when($since, fn ($q) => $q->whereDate('created_at', '>=', $since->toDateString()))
            ->when($this->option('teacher'), fn ($q) => $q->where('teacher_id', (int) $this->option('teacher')))
            ->orderBy('teacher_id')
            ->orderBy('id')
            ->get();

        $packages = TeacherPayoutPackage::query()
            ->when($this->option('teacher'), fn ($q) => $q->where('teacher_id', (int) $this->option('teacher')))
            ->where('state', '!=', TeacherPayoutPackage::STATE_REVERSED)
            ->get();

        $rows = [];
        $matched = [];

        foreach ($legacy as $payout) {
            $onDate = $payout->paid_at ?? $payout->created_at ?? Carbon::now();
            $package = $packages->first(fn (TeacherPayoutPackage $p) => (int) $p->teacher_id === (int) $payout->teacher_id
                && $p->period_start->lte($onDate) && $p->period_end->gte($onDate));

            $legacyKopecks = (int) round((float) $payout->amount * 100);

            if ($package === null) {
                $assignment = $resolver->find((int) $payout->teacher_id, $payout->course_id ? (int) $payout->course_id : null, $onDate);

                $rows[] = [
                    'class' => $assignment === null ? 'unresolved_rate' : 'missing_package',
                    'teacher_id' => (int) $payout->teacher_id,
                    'legacy_payout_id' => (int) $payout->id,
                    'date' => $onDate->toDateString(),
                    'legacy_kopecks' => $legacyKopecks,
                    'package_kopecks' => null,
                    'delta_kopecks' => null,
                    'note' => $assignment === null
                        ? 'нет датированного назначения компенсации на дату — ставка НЕ выводится (D15/D17); ждёт рулинга (ср. H5250)'
                        : 'назначение есть ('.$assignment->term->rateLabel().'), пакет ещё не построен',
                ];

                continue;
            }

            $matched[$package->getKey()] = true;
            $delta = (int) $package->total_kopecks - $legacyKopecks;

            $rows[] = [
                'class' => $delta === 0 ? 'tie' : 'delta',
                'teacher_id' => (int) $payout->teacher_id,
                'legacy_payout_id' => (int) $payout->id,
                'date' => $onDate->toDateString(),
                'legacy_kopecks' => $legacyKopecks,
                'package_kopecks' => (int) $package->total_kopecks,
                'delta_kopecks' => $delta,
                'note' => $delta === 0 ? '' : 'расхождение итога — объяснить до переключения читателей',
            ];
        }

        foreach ($packages as $package) {
            if (isset($matched[$package->getKey()])) {
                continue;
            }

            $rows[] = [
                'class' => 'orphan_package',
                'teacher_id' => (int) $package->teacher_id,
                'legacy_payout_id' => null,
                'date' => $package->period_start->toDateString(),
                'legacy_kopecks' => null,
                'package_kopecks' => (int) $package->total_kopecks,
                'delta_kopecks' => null,
                'note' => 'пакет без легаси-строки: '.$package->package_key,
            ];
        }

        $counts = array_count_values(array_column($rows, 'class'));

        if ($this->option('json')) {
            $this->line((string) json_encode(['counts' => $counts, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('H5444 сверка пакетов выплат с легаси teacher_payouts (только чтение)');
        $this->newLine();

        if ($rows === []) {
            $this->line('Нечего сверять: ни легаси-выплат, ни пакетов в выборке.');

            return self::SUCCESS;
        }

        $this->table(
            ['класс', 'teacher', 'legacy id', 'дата', 'легаси, ₽', 'пакет, ₽', 'Δ, ₽', 'примечание'],
            array_map(fn (array $r) => [
                $r['class'],
                $r['teacher_id'],
                $r['legacy_payout_id'] ?? '—',
                $r['date'],
                $r['legacy_kopecks'] === null ? '—' : number_format($r['legacy_kopecks'] / 100, 2, '.', ' '),
                $r['package_kopecks'] === null ? '—' : number_format($r['package_kopecks'] / 100, 2, '.', ' '),
                $r['delta_kopecks'] === null ? '—' : number_format($r['delta_kopecks'] / 100, 2, '.', ' '),
                $r['note'],
            ], $rows),
        );

        foreach ($counts as $class => $n) {
            $this->line(sprintf('  %-18s %d', $class, $n));
        }

        if (($counts['unresolved_rate'] ?? 0) > 0) {
            $this->newLine();
            $this->warn('Строки unresolved_rate — это НЕ дефект расчёта, а отсутствие подтверждённой ставки.');
            $this->warn('Ставку назначает человек отдельным датированным назначением компенсации; агент её не выводит.');
            $this->warn('Открытый случай: H5250 (офлайн-выплаты), ждёт рулинга MG по ставкам.');
        }

        return self::SUCCESS;
    }
}
