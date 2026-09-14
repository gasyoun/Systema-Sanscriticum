<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HomeworkSubmission;
use App\Models\User;
use App\Support\DurationLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Сигналы «застрявших» студентов для куратора. Студент считается застрявшим, если
 * выполнено хотя бы одно условие:
 *  - неактивен N+ дней (заходил раньше, но давно не появлялся);
 *  - его работу вернули на доработку и он не пересдаёт её M+ дней.
 *
 * «ДЗ висит на проверке дольше SLA» — это сигнал на стороне куратора, он уже
 * виден в очереди проверки (`HomeworkSubmissionResource`, сортировка «дольше всех
 * ждёт»), поэтому сюда не дублируется.
 */
class StuckStudentsReport
{
    /** Сколько дней без активности считаем «застреванием». */
    public const INACTIVE_DAYS = 14;

    /** Сколько дней работа на доработке без пересдачи считается «застреванием». */
    public const REWORK_STALE_DAYS = 7;

    /**
     * Запрос по застрявшим студентам (для таблицы-виджета). Подгружает только
     * «зависшие» работы на доработку, чтобы reasonsFor() не делал доп. запросов.
     */
    public static function query(): Builder
    {
        $inactiveCutoff = now()->subDays(self::INACTIVE_DAYS);
        $reworkCutoff = now()->subDays(self::REWORK_STALE_DAYS);

        return User::query()
            ->where('is_admin', false)
            ->where(function (Builder $q) use ($inactiveCutoff, $reworkCutoff) {
                // Неактивен: заходил хоть раз, но давно не появлялся.
                $q->where(function (Builder $inactive) use ($inactiveCutoff) {
                    $inactive->whereNotNull('last_login_at')
                        ->where(function (Builder $a) use ($inactiveCutoff) {
                            $a->whereNull('last_activity_at')
                                ->orWhere('last_activity_at', '<', $inactiveCutoff);
                        });
                })
                    // Или работа на доработке зависла без пересдачи.
                    ->orWhereHas('homeworkSubmissions', function (Builder $h) use ($reworkCutoff) {
                        $h->where('status', HomeworkSubmission::STATUS_NEEDS_REVISION)
                            ->where('reviewed_at', '<', $reworkCutoff);
                    });
            })
            ->with(['homeworkSubmissions' => function ($h) use ($reworkCutoff) {
                $h->where('status', HomeworkSubmission::STATUS_NEEDS_REVISION)
                    ->where('reviewed_at', '<', $reworkCutoff);
            }]);
    }

    /**
     * Человекочитаемые причины, по которым студент попал в список.
     *
     * @return list<string>
     */
    public static function reasonsFor(User $user): array
    {
        $reasons = [];

        // last_activity_at/last_login_at в этом приложении хранятся как строки
        // (см. как UserResource всюду оборачивает их в Carbon::parse) — парсим сами.
        $lastActivity = $user->last_activity_at ? Carbon::parse($user->last_activity_at) : null;
        if ($user->last_login_at && (! $lastActivity || $lastActivity->lt(now()->subDays(self::INACTIVE_DAYS)))) {
            $reasons[] = $lastActivity
                ? 'Неактивен '.DurationLabel::since($lastActivity)
                : 'Ни разу не заходил после регистрации';
        }

        $stalledRework = $user->relationLoaded('homeworkSubmissions')
            ? $user->homeworkSubmissions->count()
            : $user->homeworkSubmissions()
                ->where('status', HomeworkSubmission::STATUS_NEEDS_REVISION)
                ->where('reviewed_at', '<', now()->subDays(self::REWORK_STALE_DAYS))
                ->count();

        if ($stalledRework > 0) {
            $reasons[] = $stalledRework === 1
                ? 'ДЗ на доработке без пересдачи'
                : "ДЗ на доработке без пересдачи: {$stalledRework}";
        }

        return $reasons;
    }
}
