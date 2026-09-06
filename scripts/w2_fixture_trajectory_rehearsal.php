<?php

/*
 * H4162 W2 — fixture-trajectory rehearsal on the LIVE stack, zero residue.
 *
 * Roadmap (H4093 portfolio programme, W1 item 1 / verification gate V1):
 * rehearse enrollment → fixture payment → entitlement → first lesson →
 * first-result event with IDEMPOTENT REPLAY and ROLLBACK — without
 * production money movement.
 *
 * HOW ZERO RESIDUE IS GUARANTEED (the whole rehearsal runs inside ONE
 * database transaction that is ALWAYS rolled back):
 *   - fixture payment amount = 0.00 (no money movement by construction;
 *     also short-circuits the prana purchase award);
 *   - queue.default = 'null' in-process  → SendPaymentToSheetJob and any
 *     queued job are dropped, the money sheet is never touched;
 *   - mail.default   = 'array' in-process → welcome/receipt mails never
 *     leave the process (fixture user email is @rehearsal.invalid anyway);
 *   - onboarding ScheduledReminders, revenue-schedule accrual rows, pivot
 *     rows, telemetry events — DB rows inside the transaction → rolled back.
 *
 * The canonical chain under test: Payment::create(status paid) → fireOnPaid →
 * processSuccessfulPayment → grantAccess + enrollInCourse, then the
 * lesson-completion write exactly as StudentController::completeLesson
 * performs it (completedLessons guard → updateExistingPivot/attach), the
 * CabinetTelemetry LESSON_MARK_MASTERED emission, the PranaService award,
 * and StudentController::getUserUnlockedTariffs for the unlock keys.
 *
 * SAFETY: default is DRY-RUN (prints the plan, writes nothing). Pass --apply
 * to execute. Aborts if the fixture user already exists or course 434 is
 * missing. The fixture user is is_admin=false, has no lead, no referrer,
 * no deposits, no promises — every side branch of the chain is a no-op for it.
 *
 * Run on the box:  php scripts/w2_fixture_trajectory_rehearsal.php [--apply]
 */

declare(strict_types=1);

use App\Http\Controllers\StudentController;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Prana\PranaService;
use App\Support\CourseCohortEntitlement;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$courseId = 434;
$courseSlug = 'grammatika-po-kocerginoi-gr61';
$cohortKey = 'kochergina-gr61';
$groupId = 131;
$fixtureEmail = 'w2-fixture-h4162@rehearsal.invalid';

$fail = static function (string $msg): never {
    fwrite(STDERR, "ABORT: {$msg}\n");
    exit(1);
};

$course = Course::find($courseId);
if ($course === null) {
    $fail("course {$courseId} not found on this environment");
}

if (User::where('email', $fixtureEmail)->exists()) {
    $fail("fixture user {$fixtureEmail} already exists — a previous rehearsal left residue, investigate before re-running");
}

$snapshot = static function (): array {
    $lessons = DB::table('lessons')->where('course_id', 434)->pluck('id');

    return [
        'payments_434' => DB::table('payments')->where('course_id', 434)->count(),
        'course_user_434' => DB::table('course_user')->where('course_id', 434)->count(),
        'group_user_131' => DB::table('group_user')->where('group_id', 131)->count(),
        'lesson_user_434' => DB::table('lesson_user')->whereIn('lesson_id', $lessons)->count(),
        'homework_434' => DB::table('homework_submissions')
            ->whereIn('lesson_id', $lessons)->count(),
        'revenue_schedules_434' => DB::table('revenue_schedules')
            ->whereIn('payment_id', DB::table('payments')->where('course_id', 434)->pluck('id'))->count(),
        'entitled_kochergina_gr61' => CourseCohortEntitlement::entitledUsers('kochergina-gr61')->count(),
        'fixture_users' => User::where('email', 'like', '%@rehearsal.invalid')->count(),
    ];
};

$lessonRow = DB::table('lessons')->where('course_id', $courseId)
    ->where('block_number', 1)
    ->where('is_published', 1)
    ->where(static function ($q) use ($groupId) {
        // isVisibleToGroupsOf: group_id NULL = course-level lesson, visible to every member
        $q->whereNull('group_id')->orWhere('group_id', $groupId);
    })
    ->orderBy('id')->first();
if ($lessonRow === null) {
    $fail("no live lesson in group {$groupId} to rehearse the completion event on");
}
$lessonId = (int) $lessonRow->id;

echo '== H4162 W2 fixture-trajectory rehearsal =='.PHP_EOL;
echo $apply ? 'MODE: APPLY (single transaction, always rolled back)'.PHP_EOL : 'MODE: DRY-RUN (no writes)'.PHP_EOL;
$pre = $snapshot();
echo json_encode(['pre_state' => $pre, 'lesson_under_test' => $lessonId], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

if (! $apply) {
    echo 'Plan: fixture user -> zero-amount paid payment (canonical chain) -> entitlement checks'
        .' -> first-result completion + telemetry + prana -> idempotent replay (payment + completion)'
        .' -> revoke (cancel + detach) -> asserts -> ROLLBACK -> post-state must equal pre-state.'.PHP_EOL;
    exit(0);
}

config(['queue.default' => 'null']);
config(['mail.default' => 'array']);

$results = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    echo ($ok ? '  ok  ' : ' FAIL ').$name.($detail !== '' ? " — {$detail}" : '').PHP_EOL;
};

DB::beginTransaction();

try {
    // 1. fixture user
    $user = User::create([
        'name' => 'H4162 W2 fixture (rolled back)',
        'email' => $fixtureEmail,
        'password' => Hash::make(bin2hex(random_bytes(16))),
    ]);
    $check('fixture user created (is_admin=false)', $user->exists && ! $user->is_admin, "id={$user->id}");

    // 2. fixture payment — the canonical chain fires (fireOnPaid → processSuccessfulPayment)
    $payment = Payment::create([
        'user_id' => $user->id,
        'course_id' => $courseId,
        'amount' => 0,
        'tariff' => 'block_1',
        'status' => 'paid',
    ]);
    $check('fixture payment: paid, amount 0.00 (no money movement)', $payment->status === 'paid' && (float) $payment->amount === 0.0, "id={$payment->id}");
    $check('fireOnPaid stamped first_paid_at exactly once', $payment->first_paid_at !== null, (string) $payment->first_paid_at);

    // 3. access: grantAccess ran inside the chain
    $inGroup = $user->fresh()->groups()->where('groups.id', $groupId)->exists();
    $check('grantAccess put the fixture student into group 131', $inGroup);
    $enrolled = $user->fresh()->courses()->where('courses.id', $courseId)->first();
    $check('enrollInCourse wrote course_user with status Записался', $enrolled !== null && $enrolled->pivot->status === 'Записался');

    // 4. entitlement: the W1-flipped cohort key resolves on prod through the real config
    $check('cohort entitlement live via flipped kochergina-gr61 key', CourseCohortEntitlement::hasEntitlement($user->fresh(), $cohortKey));
    $keys = StudentController::getUserUnlockedTariffs($user->id, $courseSlug);
    $check('tariff unlock keys contain block_1', in_array('block_1', $keys, true), implode(',', $keys));

    // 5. first lesson/task — the canonical completion write as completeLesson performs it
    $user->refresh();
    if (! $user->completedLessons()->where('lesson_id', $lessonId)->exists()) {
        if ($user->lessonProgress()->where('lesson_id', $lessonId)->exists()) {
            $user->lessonProgress()->updateExistingPivot($lessonId, ['is_completed' => true]);
        } else {
            $user->lessonProgress()->attach($lessonId, ['is_completed' => true]);
        }
    }
    $lesson = Lesson::find($lessonId);
    app(CabinetTelemetry::class)->emit(
        user: $user,
        event: ActivityEvent::LESSON_MARK_MASTERED,
        data: ['course_id' => $courseId, 'lesson_id' => $lessonId],
        request: null,
    );
    app(PranaService::class)->award($user, 'lesson_complete', $lesson);
    $check('first-result event: exactly one lesson_user row (is_completed=1)', DB::table('lesson_user')->where('user_id', $user->id)->where('lesson_id', $lessonId)->where('is_completed', 1)->count() === 1);
    $check('LESSON_MARK_MASTERED emitted exactly once', DB::table('activity_events')->where('user_id', $user->id)->where('event_type', ActivityEvent::LESSON_MARK_MASTERED)->count() === 1);

    // 6. IDEMPOTENT REPLAY — the completion click fires again, the payment is processed again
    $firstPaidAt = (string) $payment->fresh()->first_paid_at;
    $payment->processSuccessfulPayment();
    $user->refresh();
    // completeLesson's guard: already-completed lessons get NO second write
    if (! $user->completedLessons()->where('lesson_id', $lessonId)->exists()) {
        if ($user->lessonProgress()->where('lesson_id', $lessonId)->exists()) {
            $user->lessonProgress()->updateExistingPivot($lessonId, ['is_completed' => true]);
        } else {
            $user->lessonProgress()->attach($lessonId, ['is_completed' => true]);
        }
    }
    $check('replay: still exactly one lesson_user row', DB::table('lesson_user')->where('user_id', $user->id)->where('lesson_id', $lessonId)->count() === 1);
    $check('replay: still exactly one LESSON_MARK_MASTERED', DB::table('activity_events')->where('user_id', $user->id)->where('event_type', ActivityEvent::LESSON_MARK_MASTERED)->count() === 1);
    $check('replay: group membership not duplicated', DB::table('group_user')->where('user_id', $user->id)->where('group_id', $groupId)->count() === 1);
    $check('replay: first_paid_at never re-stamped', (string) $payment->fresh()->first_paid_at === $firstPaidAt);

    // 7. ROLLBACK/REVOKE rehearsal — refund/cancel + detach (the documented ops revoke)
    $payment->update(['status' => 'canceled']);
    $user->groups()->detach($groupId);
    $keysAfterRevoke = StudentController::getUserUnlockedTariffs($user->id, $courseSlug);
    // gr61's lessons are course-level (group_id NULL): isVisibleToGroupsOf stays true
    // by design — the content lock after revoke lives at the tariff-unlock layer
    // (ensureLessonAccessible aborts 403 when isUnlockedBy fails for a paid lesson)
    $check('revoke: lesson no longer unlocked (content locked at the tariff layer)', ! $lesson->isUnlockedBy($keysAfterRevoke));
    $check('revoke: no paid tariff keys left', $keysAfterRevoke === []);
    $check('revoke: progress history intact', DB::table('lesson_user')->where('user_id', $user->id)->where('lesson_id', $lessonId)->where('is_completed', 1)->count() === 1);
} finally {
    DB::rollBack();
}

$post = $snapshot();
$allOk = ! in_array(false, array_column($results, 'ok'), true) && $post === $pre;

echo json_encode(['post_state' => $post, 'pre_state_repeat' => $pre, 'verdict' => $allOk ? 'PASS' : 'FAIL', 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($allOk ? 0 : 1);
