<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\LessonAccessGrant;
use App\Services\CertificateService;
use App\Services\CourseMaterialsArchiver;

trait ManagesCourseResources
{
    /**
     * Скачать архив со всеми материалами курса.
     * Учитывает права доступа студента (оплаченные блоки).
     */
    public function downloadCourseMaterials(string $slug, CourseMaterialsArchiver $archiver)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // Проверяем, что курс доступен этому студенту (он в нужной группе).
        // Используем is_active (а не is_visible) — это видимость в ЛК, согласованно с dashboard/showCourse.
        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);
        abort_unless(
            $course->groups()->whereIn('groups.id', $userGroupIds)->exists(),
            404
        );

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $course->slug);

        if (empty($unlockedTariffs)) {
            return back()->with('error', 'У вас нет оплаченных блоков для этого курса.');
        }

        try {
            return $archiver->buildForUser($course, $user, $unlockedTariffs);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Библиотека курса — реестр ссылок на литературу (фаза 1).
     *
     * Доступ: курс активен + студент в группе курса. Тарифы НЕ гейтят страницу
     * целиком — иначе общая библиография курса исчезала бы в промежутках между
     * оплатами блоков. Материал, привязанный к уроку, наследует замок этого
     * урока по той же формуле, что и showCourse().
     */
    public function courseLibrary(string $slug)
    {
        abort_unless((bool) config('features.course_library', false), 404);

        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);
        abort_unless(
            $course->groups()->whereIn('groups.id', $userGroupIds)->exists(),
            404
        );

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $course->slug);
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);

        $materials = CourseMaterial::query()
            ->with('lesson')
            ->where('course_id', $course->id)
            ->visible()
            ->shelfOrder()
            ->get()
            ->filter(function (CourseMaterial $material) use ($unlockedTariffs, $grantedLessonIds): bool {
                // Материал курса (без урока) виден всем, у кого есть курс.
                if ($material->lesson_id === null) {
                    return true;
                }
                $lesson = $material->lesson;
                // Урок удалён/недоступен — материал не показываем.
                if ($lesson === null) {
                    return false;
                }

                return $lesson->is_free
                    || $lesson->is_preview
                    || in_array($lesson->id, $grantedLessonIds, true)
                    || $lesson->isUnlockedBy($unlockedTariffs);
            })
            ->values();

        // Полка курса отдельно от полок уроков — так студент видит общую
        // библиографию, не пролистывая её сквозь уроки.
        $courseWide = $materials->whereNull('lesson_id')->values();
        $byLesson = $materials->whereNotNull('lesson_id')->groupBy('lesson_id');

        return view('student.course-library', [
            'course' => $course,
            'courseWide' => $courseWide,
            'byLesson' => $byLesson,
        ]);
    }

    /**
     * Скачивание сертификата
     */
    public function downloadCertificate($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);
        $pdf = $service->generatePdf($certificate);

        return $pdf->download('Certificate_'.$certificate->course->id.'.pdf');
    }

    /**
     * Скачивание сертификата картинкой (JPEG).
     */
    public function downloadCertificateImage($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);

        try {
            $jpeg = $service->generateJpegBytes($certificate);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(
            fn () => print $jpeg,
            'Certificate_'.$certificate->course->id.'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }
}
