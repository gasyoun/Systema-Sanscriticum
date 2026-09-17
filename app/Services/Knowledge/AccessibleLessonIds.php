<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Access\LessonGate;
use Illuminate\Support\Facades\Cache;

/**
 * Этап 4 — какие уроки с расшифровкой студенту реально открыты.
 *
 * Решение принимает ОДИН гейт: {@see LessonGate::canWatch()} — тот же, что
 * стоит на выдаче стенограммы (`GET /c/{slug}/u/{id}/transcript`). Своей копии
 * правил доступа здесь нет и быть не должно: в репозитории уже четыре
 * разошедшиеся реализации этой цепочки (StudentController::showLesson,
 * RecordingGateController, ensureLessonAccessible, AccessDiagnosticsService),
 * и пятая ошиблась бы ровно там, где ошибаться нельзя — на платном контенте.
 *
 * Кандидатов мало (уроки с расшифровкой, на 16-09-2026 их 44), поэтому перебор
 * в PHP дешевле любой попытки выразить пересечение ключей тарифов в SQL —
 * `array_intersect(unlockingKeys(), ownedKeys)` в репозитории не выражен в SQL
 * нигде.
 *
 * Кэш нарочно короткий (60 секунд). Он тут не ради скорости — перебор 44
 * уроков дёшев, — а чтобы поток сообщений в диалоге не дёргал гейт на каждую
 * реплику. Дольше держать нельзя в обе стороны: доплативший блок студент не
 * должен ждать доступа, а у отозванного (возврат, истёкшее окно) не должно
 * оставаться окна, в котором бот ещё цитирует платное занятие.
 */
final class AccessibleLessonIds
{
    private const CACHE_TTL = 60;

    public function __construct(private readonly LessonGate $gate) {}

    /** @return list<int> */
    public function forUser(User $user): array
    {
        return Cache::remember(
            'lesson_qa.accessible.'.$user->id,
            self::CACHE_TTL,
            fn (): array => $this->compute($user),
        );
    }

    /** Сбросить кэш (оплата, грант, смена группы). */
    public function forget(User $user): void
    {
        Cache::forget('lesson_qa.accessible.'.$user->id);
    }

    /** @return list<int> */
    private function compute(User $user): array
    {
        $candidates = Lesson::query()
            ->with('course')
            ->where('is_published', true)
            ->withTranscript()
            ->get();

        $allowed = [];
        foreach ($candidates as $lesson) {
            if ($this->gate->canWatch($user, $lesson)) {
                $allowed[] = (int) $lesson->id;
            }
        }

        return $allowed;
    }
}
