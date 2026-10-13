<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Support\RoleGate;
use App\Support\Roles;
use Illuminate\Http\Request;

/**
 * Живой предпросмотр документа вехи (iframe в форме вех —
 * CertificateMilestonesRelationManager). Рендерит ТЕТ САМ блейд
 * certificates.default, которым dompdf печатает PDF, поэтому вёрстка в
 * превью один в один; изоляция в iframe защищает от CSS админки.
 *
 * Отличия от боевого рендера (CertificateService::generatePdf): QR не
 * генерируется, номер/дата — заглушки, ФИО — «Фамилия Имя», а фон отдаётся
 * URL-ом, а не base64 (браузер кэширует картинку между перерисовками
 * live-поля).
 */
class MilestoneCertificatePreviewController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless($user && RoleGate::any(Roles::ADMIN, Roles::TEACHER), 403);

        $data = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'template' => ['nullable', 'in:'.implode(',', array_keys(Certificate::TEMPLATES))],
            'document_type' => ['nullable', 'in:'.implode(',', array_keys(CertificateMilestone::DOCUMENT_TYPES))],
            'title' => ['nullable', 'string', 'max:500'],
        ]);

        $course = isset($data['course_id'])
            ? Course::query()->findOrFail($data['course_id'])
            : null;

        // Преподаватель превьюет только свои курсы — зеркалит scope формы вех.
        if ($user->isTeacher() && $course) {
            $owns = Course::query()
                ->forTeacher($user->teacher_id)
                ->whereKey($course->getKey())
                ->exists();
            abort_unless($owns, 403);
        }

        $title = filled(trim((string) ($data['title'] ?? '')))
            ? trim((string) $data['title'])
            : ($course->title ?? 'Название курса');

        $certificate = new Certificate([
            'student_name' => 'Фамилия Имя',
            'course_title' => $title,
            'template' => $data['template'] ?? 'gasuns',
            'document_type' => $data['document_type'] ?? CertificateMilestone::DOC_CERTIFICATE,
        ]);
        $certificate->setRelation('course', $course);

        return response()->view('certificates.default', [
            'certificate' => $certificate,
            'user' => null,
            'course' => $course,
            'student_name' => 'Фамилия Имя',
            'course_title' => $title,
            'qr_image' => null,
            'bg_base64' => $certificate->backgroundUrl(),
            'date' => 'дд.мм.гггг',
            'number' => 'ГГГГ-XXXXX',
        ]);
    }
}
