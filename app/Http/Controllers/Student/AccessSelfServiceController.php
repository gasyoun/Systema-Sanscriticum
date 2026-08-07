<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\AccessDiagnosticsService;
use App\Services\BlockAccessMaterializer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Student-facing access self-heal (Phase 1).
 *
 * Only safe action that writes: re-run BlockAccessMaterializer on the student's
 * own paid range payments for a course. Money amounts unchanged (zero siblings).
 */
class AccessSelfServiceController extends Controller
{
    public function materialize(
        Request $request,
        string $slug,
        AccessDiagnosticsService $diagnostics,
        BlockAccessMaterializer $materializer,
    ): RedirectResponse {
        abort_unless((bool) config('features.access_self_service', false), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);

        // Student must belong to a group of this course (same gate as showCourse).
        $inGroup = $course->groups()
            ->whereIn('groups.id', $user->groups->pluck('id'))
            ->exists();
        abort_unless($inGroup, 403);

        $payments = $diagnostics->materializablePayments($user, (int) $course->id);
        $created = 0;
        foreach ($payments as $payment) {
            // Defence in depth: only own rows (query already scoped; assert again).
            if ((int) $payment->user_id !== (int) $user->id) {
                abort(403);
            }
            $created += $materializer->materialize($payment);
        }

        $message = $created > 0
            ? 'Открыли оплаченные блоки (добавлено ключей: '.$created.'). Обновите список уроков.'
            : 'Ключи доступа уже на месте — если урок всё ещё закрыт, проверьте оплату или позовите куратора.';

        return back()->with('access_status', $message);
    }
}
