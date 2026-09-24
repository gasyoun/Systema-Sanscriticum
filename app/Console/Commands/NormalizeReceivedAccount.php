<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\Teacher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H5474: привести `payments.received_account` к домену ('school','teacher_personal').
 *
 * На проде 24-09-2026 найдено 10 строк со значением 'teacher' — его не пишет ни одна
 * строка кода репозитория (источник — сырой SQL/tinker), и оно не равно ни одной
 * константе. Последствия разъезда описаны в
 * docs/EVIDENCE_H5474_RECEIVED_ACCOUNT_DOMAIN_PROD_PROBE_24-09-2026.md.
 *
 * ПОЧЕМУ ДЕФОЛТ — 'school', а не 'teacher_personal'.
 * Сегодня 'teacher' !== RECEIVED_TEACHER, поэтому isCourseRevenuePayment() СЧИТАЕТ
 * такую строку выручкой курса: преподаватель курса уже получил на неё свой процент,
 * и это начисление верное — он курс вёл. Перевод в 'teacher_personal' ОТОБРАЛ БЫ у
 * него эту долю (на проде — 15 638 ₽ у двух преподавателей), не вернув при этом ни
 * копейки с держателя денег: directReceiptsForTeacher() ходит только по
 * allTaughtCourses() получателя, а получатель (teacher_id=3) этих курсов не ведёт.
 * То есть наивный релейбл делает ХУЖЕ обеим сторонам. 'school' же не меняет ни одного
 * начисления: пер-блочный калькулятор уже считал эти строки выручкой, а сводные
 * читатели (schoolReceived()) наконец начинают видеть то же самое.
 *
 * Обязательство держателя денег перед кассой — отдельный вопрос взаиморасчёта
 * (MutualSettlement), а не признак `received_account`; решение за MG.
 *
 * Команда по умолчанию — СУХОЙ ПРОГОН. Запись только с --apply, каждое изменение
 * пишет строку PaymentAudit (append-only).
 */
class NormalizeReceivedAccount extends Command
{
    /**
     * Полный домен `received_account` — третьего значения не существует.
     *
     * РЕЗИДУАЛ H5474: этой константе место в Payment (рядом с RECEIVED_SCHOOL /
     * RECEIVED_TEACHER), чтобы единый предикат читали и isCourseRevenuePayment(),
     * и MutualSettlementService, и scopeSchoolReceived(). Правка Payment.php —
     * money-contour hard-confirm, она требует человека; см. PR-описание.
     *
     * @var list<string>
     */
    private const DOMAIN = [Payment::RECEIVED_SCHOOL, Payment::RECEIVED_TEACHER];

    protected $signature = 'money:normalize-received-account
        {--apply : выполнить запись (по умолчанию — только сухой прогон)}
        {--to=school : целевое значение: school|teacher_personal}
        {--id=* : ограничить конкретными id платежей}';

    protected $description = 'H5474: нормализовать payments.received_account к домену (school|teacher_personal). Dry-run по умолчанию.';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        if (! in_array($to, self::DOMAIN, true)) {
            $this->error(sprintf('--to=%s недопустимо; домен: %s', $to, implode('|', self::DOMAIN)));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $ids = array_map('intval', (array) $this->option('id'));

        $query = Payment::query()
            ->whereNotIn('received_account', self::DOMAIN)
            ->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('Строк вне домена нет — ничего делать не нужно.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s: %d строк вне домена → %s', $apply ? 'ПРИМЕНЕНИЕ' : 'СУХОЙ ПРОГОН', $rows->count(), $to));
        $this->newLine();

        $blocked = [];
        $plan = [];
        foreach ($rows as $row) {
            $reason = $to === Payment::RECEIVED_TEACHER ? $this->refuseTeacherPersonal($row) : null;
            if ($reason !== null) {
                $blocked[] = [$row->id, $reason];

                continue;
            }
            $plan[] = $row;
        }

        $this->table(
            ['id', 'курс', 'тариф', 'сумма', 'валюта', 'было', 'станет'],
            array_map(fn (Payment $p) => [
                $p->id,
                $p->course_id,
                $p->tariff,
                (string) $p->amount,
                $p->foreign_currency ?? '—',
                (string) $p->getOriginal('received_account'),
                $to,
            ], $plan)
        );

        if ($blocked !== []) {
            $this->newLine();
            $this->warn('ОТКАЗАНО (перевод в teacher_personal отобрал бы долю у преподавателя курса):');
            foreach ($blocked as [$id, $reason]) {
                $this->line(sprintf('  #%d — %s', $id, $reason));
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не записано. Повторите с --apply, сверив таблицу выше.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($plan, $to, &$written): void {
            foreach ($plan as $row) {
                $before = (string) $row->received_account;
                $beforeTeacher = $row->received_by_teacher_id;

                // saving() сам чистит received_by_teacher_id для не-teacher_personal.
                $row->received_account = $to;
                $row->save();

                PaymentAudit::create([
                    'payment_id' => $row->id,
                    'admin_id' => null,
                    'admin_name' => 'H5474 money:normalize-received-account',
                    'action' => PaymentAudit::ACTION_UPDATED,
                    'amount' => $row->amount,
                    'changes' => [
                        'received_account' => [$before, $row->received_account],
                        'received_by_teacher_id' => [$beforeTeacher, $row->received_by_teacher_id],
                    ],
                    'created_at' => now(),
                ]);
                $written++;
            }
        });

        $this->newLine();
        $this->info(sprintf('Записано строк: %d (каждая — со строкой PaymentAudit).', $written));

        return self::SUCCESS;
    }

    /**
     * Перевод в teacher_personal допустим, только если получатель денег назван И
     * реально ведёт курс платежа. Иначе строка выпадет из выручки курса (его
     * преподаватель теряет свой процент), но и в зачёт получателю не попадёт —
     * directReceiptsForTeacher() перебирает только allTaughtCourses() получателя.
     * Именно эта ловушка сработала бы на проде (H5474).
     */
    private function refuseTeacherPersonal(Payment $row): ?string
    {
        $teacherId = (int) $row->received_by_teacher_id;
        if ($teacherId === 0) {
            return 'не назван received_by_teacher_id';
        }

        $teacher = Teacher::find($teacherId);
        if ($teacher === null) {
            return sprintf('преподаватель #%d не найден', $teacherId);
        }

        if ($row->course_id !== null && ! $teacher->allTaughtCourses()->contains('id', (int) $row->course_id)) {
            return sprintf(
                'преподаватель #%d не ведёт курс #%d — зачёт не состоится, а доля преподавателя курса пропадёт',
                $teacherId,
                (int) $row->course_id
            );
        }

        if ($teacher->payout_currency === null || $row->foreign_currency !== $teacher->payout_currency) {
            return sprintf(
                'валюта строки (%s) не равна валюте выплаты преподавателя #%d (%s) — зачёт помечается mismatch и в total не войдёт',
                $row->foreign_currency ?? '∅',
                $teacherId,
                $teacher->payout_currency ?? '∅'
            );
        }

        return null;
    }
}
