<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * H3913: отчёт «платежи без привязки к студенту» (сиротские платежи) —
 * read-only. Никаких привязок/правок: страница только показывает дыру,
 * разбор «чьи это деньги» остаётся ручной работой куратора.
 *
 * Сирота = реально оплаченный (paid) настоящий (не conditional) платёж, у
 * которого user_id NULL вообще либо user_id указывает на аккаунт супер-админа
 * (платёж создали руками «под собой»). Реальный кейс: 3 счёта по 8000 ₽ за
 * блок 1 Летнего интенсива, записанные от SuperAdmin 06-08 и 13-08.
 *
 * Подсказка кандидата — best-effort: ученики этого же курса, платившие ТА ЖЕ
 * СУММУ, отсортированы по близости даты к платёжу-сироте (окно
 * CANDIDATE_WINDOW_DAYS). Совпадение по сумме вне окна тоже показывается
 * (слабый сигнал), но после кандидатов «близкой даты». Это НЕ утверждение
 * «платил этот человек» — только зацепка для сопоставления с перепиской.
 */
class OrphanPaymentsReport
{
    /** Окно «близкой даты» для кандидатов (дней). */
    public const CANDIDATE_WINDOW_DAYS = 30;

    /** Максимум кандидатов в подсказке одной строки. */
    public const MAX_CANDIDATES = 3;

    /** Кэш кандидатов в рамках одного запроса: payment_id => rows. */
    private array $candidateCache = [];

    /**
     * Eloquent-запрос сиротских платежей для Filament-таблицы: базовый запрос
     * по payments (не UNION), поэтому группировки/поиск/сортировки Filament
     * работают как над обычным Eloquent-запросом.
     */
    public function query(): Builder
    {
        return Payment::query()
            ->paid()
            ->real()
            ->where(function (Builder $q) {
                $q->whereNull('payments.user_id')
                    ->orWhereHas('user', fn (Builder $uq) => $uq->where('role', Roles::SUPER_ADMIN));
            })
            ->with('course');
    }

    /**
     * Названия курсов, в которых лежат сироты — для колонки и фильтра.
     *
     * @return array<int, string>
     */
    public function courseTitles(): array
    {
        $courseIds = $this->query()
            ->whereNotNull('course_id')
            ->pluck('course_id')
            ->unique()
            ->values()
            ->all();

        if ($courseIds === []) {
            return [];
        }

        return Course::query()->whereIn('id', $courseIds)->pluck('title', 'id')->all();
    }

    /**
     * Кандидаты-подсказка для одного сироты (см. шапку класса).
     *
     * @return list<array{user_id: int, name: string, date: string, diff_days: int, near: bool}>
     */
    public function candidatesFor(Payment $payment): array
    {
        $key = (int) $payment->getKey();
        if (array_key_exists($key, $this->candidateCache)) {
            return $this->candidateCache[$key];
        }

        return $this->candidateCache[$key] = $this->computeCandidates($payment);
    }

    /**
     * Готовая строка-подсказка для Filament-колонки.
     */
    public function candidateLabel(Payment $payment): ?string
    {
        $candidates = $this->candidatesFor($payment);
        if ($candidates === []) {
            return null;
        }

        $bits = array_map(
            fn (array $c): string => $c['name'].' · '.$c['date']
                .($c['near'] ? ' · Δ'.$c['diff_days'].' дн' : ' (далеко)'),
            $candidates,
        );

        return implode("\n", $bits);
    }

    /**
     * Сбросить кэш кандидатов (тесты переживают несколько прогонов в одном
     * процессе — после RefreshDatabase id начинаются заново).
     */
    public function flushCandidateCache(): void
    {
        $this->candidateCache = [];
    }

    /**
     * @return list<array{user_id: int, name: string, date: string, diff_days: int, near: bool}>
     */
    private function computeCandidates(Payment $payment): array
    {
        $courseId = $payment->course_id !== null ? (int) $payment->course_id : null;
        $orphanDate = $payment->created_at !== null ? Carbon::parse($payment->created_at) : null;

        if ($courseId === null || $orphanDate === null) {
            return [];
        }

        // Платежи учеников этого курса на ту же сумму. Исключаем саму сироту
        // (по id) и другие записи супер-админа — кандидат только человек.
        $rows = Payment::query()
            ->paid()
            ->real()
            ->where('course_id', $courseId)
            ->where('amount', (float) $payment->amount)
            ->whereNotNull('user_id')
            ->when($payment->user_id !== null, fn (Builder $q) => $q->where('user_id', '!=', $payment->user_id))
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw(1)
                    ->from('users')
                    ->whereColumn('users.id', 'payments.user_id')
                    ->where('users.role', Roles::SUPER_ADMIN);
            })
            ->get(['id', 'user_id', 'created_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->pluck('name', 'id');

        $candidates = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at);
            $diff = (int) abs($orphanDate->diffInDays($date, false));
            $candidates[] = [
                'user_id' => (int) $row->user_id,
                'name' => (string) ($names[$row->user_id] ?? '#'.$row->user_id),
                'date' => $date->format('d.m.Y'),
                'diff_days' => $diff,
                'near' => $diff <= self::CANDIDATE_WINDOW_DAYS,
            ];
        }

        // Сначала «близкой даты» (по возрастанию разрыва), потом остальные.
        usort($candidates, fn (array $a, array $b): int => [$b['near'], $a['diff_days']] <=> [$a['near'], $b['diff_days']]);

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }
}
