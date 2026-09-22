<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StudentCertificateConcerns;
use App\Http\Controllers\Concerns\StudentCourseContentConcerns;
use App\Http\Controllers\Concerns\StudentDashboardConcerns;
use App\Http\Controllers\Concerns\StudentMiscConcerns;
use App\Http\Controllers\Concerns\StudentScheduleConcerns;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

class StudentController extends Controller
{
    use StudentCertificateConcerns;
    use StudentCourseContentConcerns;
    use StudentDashboardConcerns;
    use StudentMiscConcerns;
    use StudentScheduleConcerns;

    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Получение купленных тарифов ===
     * Железобетонный метод проверки доступов строго по ID КУРСА
     *
     * H3308: public static — тот же доступ-список переиспользует гейт
     * GatedAssetController (стенограмма/материалы/файлы ДЗ), чтобы не плодить
     * вторую реализацию правила «что куплено».
     */
    public static function getUserUnlockedTariffs($userId, $courseSlug): array
    {
        // 1. Находим ID курса по каноническому slug или alias
        $course = Course::resolveBySlug($courseSlug);
        $courseId = $course?->id;

        // Если курс не найден, возвращаем пустой массив (нет доступов)
        if (! $courseId) {
            return [];
        }

        // 2. Ищем оплаченные тарифы строго по ID КУРСА, а не лендинга.
        //    Учитываем оба статуса оплаты ('paid' и 'success') — иначе урок
        //    остаётся закрытым для success-платежей, хотя группа уже выдана.
        //    H4396: withAccessExpiry — conditional («под обещание») ключи живут
        //    только пока живо обещание (флаг conditional_access_expiry, дефолт
        //    OFF; census §C.1 — expiry-предикат на payment-keyed доступ).
        $keys = Payment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->paid()
            ->withAccessExpiry()
            ->pluck('tariff')
            ->toArray();

        // 3. H2644: клубное членство добавляет виртуальный ключ на курсы полки
        //    (club_included). Ключ существует только на время запроса и нигде
        //    не сохраняется — истёкшее членство закрывает доступ немедленно,
        //    без миграции и без «забытых» строк в payments.
        return array_values(array_unique(array_merge(
            $keys,
            app(ClubEntitlement::class)->extraTariffKeys(
                $userId ? User::find($userId) : null,
                $course,
            ),
        )));
    }
}
