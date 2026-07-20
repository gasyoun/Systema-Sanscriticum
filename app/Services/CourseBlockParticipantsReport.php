<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сводка по курсу: сколько участников в его группах и сколько студентов
 * оплатили доступ — с разбивкой по блокам и половинам.
 *
 * «Оплатил блок N» = есть оплаченная (paid/success, не conditional) запись,
 * чей тариф/диапазон открывает блок — см. Payment::coversBlockHalf, который
 * зеркалит Lesson::unlockingKeys. В рублях/ЗП ничего не считается, только люди.
 */
class CourseBlockParticipantsReport
{
    /**
     * @return array{
     *   participants_total: int,
     *   groups: array<int, array{name: string, count: int}>,
     *   blocks: array<int, array{number: int, title: ?string, starts_at: ?Carbon, whole: int, half1: int, half2: int}>,
     *   paid_any: int,
     *   block_numbers: array<int, int>,
     *   students: array<int, array{id: int, name: string, blocks: array<int, ?string>}>
     * }
     */
    public function forCourse(Course $course): array
    {
        $groupIds = $course->groups()->pluck('groups.id');

        // Участники = уникальные пользователи во всех группах курса + по группам.
        $participantsTotal = DB::table('group_user')
            ->whereIn('group_id', $groupIds)
            ->distinct()
            ->count('user_id');

        $groups = $course->groups()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn ($g): array => ['name' => $g->name, 'count' => (int) $g->users_count])
            ->all();

        $blocks = $course->blocks()->get();

        // Оплаченные платежи курса (без «обещанного» conditional-доступа).
        $payments = $course->payments()
            ->paid()
            ->where(fn ($q) => $q->whereNull('is_conditional')->orWhere('is_conditional', false))
            ->get(['id', 'user_id', 'tariff', 'start_block', 'end_block', 'is_conditional', 'status']);

        $paidAny = [];

        $blockRows = $blocks->map(function (CourseBlock $block) use ($payments, &$paidAny): array {
            $n = (int) $block->number;
            $h1 = $h2 = $whole = [];

            foreach ($payments as $p) {
                if (! $p->user_id) {
                    continue;
                }
                $c1 = $p->coversBlockHalf($n, 1);
                $c2 = $p->coversBlockHalf($n, 2);

                if ($c1) {
                    $h1[$p->user_id] = true;
                    $paidAny[$p->user_id] = true;
                }
                if ($c2) {
                    $h2[$p->user_id] = true;
                    $paidAny[$p->user_id] = true;
                }
                if ($c1 && $c2) {
                    $whole[$p->user_id] = true;
                }
            }

            return [
                'number' => $n,
                'title' => $block->title,
                'starts_at' => $block->starts_at,
                'whole' => count($whole),
                'half1' => count($h1),
                'half2' => count($h2),
            ];
        })->all();

        // Матрица «студент × блок»: какой блок студент оплатил (целиком/половина/нет).
        // Только студенты с хотя бы одной оплатой блока этого курса.
        $blockNumbers = $blocks->pluck('number')->map(fn ($n) => (int) $n)->all();
        $paymentsByUser = $payments->whereNotNull('user_id')->groupBy('user_id');

        $names = User::whereIn('id', array_keys($paidAny))
            ->orderBy('name')
            ->pluck('name', 'id');

        $students = [];
        foreach ($names as $uid => $name) {
            $userPayments = $paymentsByUser->get($uid, collect());
            $row = ['id' => (int) $uid, 'name' => (string) $name, 'blocks' => []];

            foreach ($blockNumbers as $n) {
                $c1 = $userPayments->contains(fn (Payment $p) => $p->coversBlockHalf($n, 1));
                $c2 = $userPayments->contains(fn (Payment $p) => $p->coversBlockHalf($n, 2));
                $row['blocks'][$n] = $c1 && $c2 ? 'whole' : ($c1 ? 'half1' : ($c2 ? 'half2' : null));
            }

            $students[] = $row;
        }

        return [
            'participants_total' => $participantsTotal,
            'groups' => $groups,
            'blocks' => $blockRows,
            'paid_any' => count($paidAny),
            'block_numbers' => $blockNumbers,
            'students' => $students,
        ];
    }
}
