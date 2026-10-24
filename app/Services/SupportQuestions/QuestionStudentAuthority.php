<?php

namespace App\Services\SupportQuestions;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Существующий студенческий авторитет для аналитики вопросов (H5709).
 *
 * «Подтверждённый студент» = действующее членство в учебной группе
 * (group_user, left_at IS NULL) ИЛИ хотя бы один проведённый платёж
 * (Payment::PAID_STATUSES — канонический набор, не дублируем литералы).
 * Оба признака уже существуют в кодовой базе (activeGroups/payments) и
 * идентичны данным кабинета; возраст аккаунта (isEstablishedClaimStudent)
 * НЕ используется — это анти-фрод доверия к claim-сессиям, а не маркер
 * студенчества, и на прозпектах он давал бы ложные срабатывания.
 *
 * Определение попадает в каждый снапшот строкой
 * QuestionPopulationResolver::STUDENT_DEFINITION — сравнение окон с другим
 * определением блокируется как несовместимое.
 */
class QuestionStudentAuthority
{
    /**
     * Карта user_id => подтверждённый студент, одним запросом на признак.
     *
     * @param  Collection<int, int>  $userIds
     * @return array<int, bool>
     */
    public function confirmedStudentMap(Collection $userIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $ids = $userIds->map(fn ($id): int => (int) $id)->unique()->values();

        $inGroup = DB::table('group_user')
            ->whereIn('user_id', $ids)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $paid = Payment::query()
            ->paid()
            ->whereIn('user_id', $ids)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = in_array($id, $inGroup, true) || in_array($id, $paid, true);
        }

        return $map;
    }

    public function isConfirmedStudent(User $user): bool
    {
        return $this->confirmedStudentMap(collect([(int) $user->id]))[(int) $user->id] ?? false;
    }
}
