<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-only access diagnostics for student self-service Phase 1.
 *
 * Classifies why a lesson/block is locked and which safe action to offer.
 * Does NOT write payments/keys — materialize lives in AccessSelfServiceController.
 *
 * Spec: docs/access-self-service-spec.md (rulings 07-08-2026).
 */
class AccessDiagnosticsService
{
    public const CODE_KEY_MISSING = 'key_missing_for_paid_range';

    public const CODE_NOT_CONNECTED_BOT = 'not_connected_bot';

    public const CODE_LOGIN_ISSUE = 'login_issue';

    public const CODE_NOT_PAID = 'not_paid';

    public const CODE_PAYMENT_CHECK = 'payment_check';

    public const CODE_NO_FINDING = 'no_finding';

    public const ACTION_MATERIALIZE = 'materialize';

    public const ACTION_CONNECT_BOT = 'connect_bot';

    public const ACTION_RESET_PASSWORD = 'reset_password';

    public const ACTION_CHECK_PAYMENT = 'check_payment';

    public const ACTION_PAY_DEBT = 'pay_debt';

    public const ACTION_CALL_CURATOR = 'call_curator';

    /**
     * Findings for one locked (or allegedly broken) lesson.
     *
     * @param  array<int, string>  $ownedKeys  payments.tariff keys already loaded by the controller
     * @return list<array{
     *   code: string,
     *   message: string,
     *   action: string|null,
     *   action_label: string|null,
     *   course_id: int|null,
     *   course_slug: string|null,
     *   block_number: int|null
     * }>
     */
    public function forLesson(User $user, Lesson $lesson, Course $course, array $ownedKeys = []): array
    {
        if ($ownedKeys === []) {
            $ownedKeys = $this->ownedKeys($user, (int) $course->id);
        }

        if ($this->lessonIsOpen($lesson, $ownedKeys, $user, (int) $course->id)) {
            return [$this->finding(
                self::CODE_NO_FINDING,
                'Доступ к этому уроку в порядке. Если вы всё равно его не видите — напишите куратору.',
                self::ACTION_CALL_CURATOR,
                'Позвать куратора',
                (int) $course->id,
                $course->slug,
                $lesson->block_number !== null ? (int) $lesson->block_number : null,
            )];
        }

        $block = $lesson->block_number !== null ? (int) $lesson->block_number : null;
        $findings = [];

        if ($block !== null && $this->hasPaidCoverageMissingKey($user, (int) $course->id, $block, $ownedKeys)) {
            $findings[] = $this->finding(
                self::CODE_KEY_MISSING,
                'Оплата за блок '.$block.' есть, но ключ доступа не выписан. Нажмите «Открыть оплаченные блоки» — это безопасно и идемпотентно.',
                self::ACTION_MATERIALIZE,
                'Открыть оплаченные блоки',
                (int) $course->id,
                $course->slug,
                $block,
            );
        } elseif ($block !== null && $this->hasPendingOrFailedForBlock($user, (int) $course->id, $block)) {
            $findings[] = $this->finding(
                self::CODE_PAYMENT_CHECK,
                'Есть незавершённая или неуспешная оплата по этому блоку. Проверьте статус на странице «Оплата и доступ».',
                self::ACTION_CHECK_PAYMENT,
                'Проверить статус оплаты',
                (int) $course->id,
                $course->slug,
                $block,
            );
        } else {
            $findings[] = $this->finding(
                self::CODE_NOT_PAID,
                $block !== null
                    ? 'Блок '.$block.' не покрыт оплатой. Оплатите его в «Оплата и доступ» или «Мои долги».'
                    : 'Урок закрыт: нет оплаченного доступа. Откройте «Оплата и доступ».',
                self::ACTION_PAY_DEBT,
                'Перейти к оплате',
                (int) $course->id,
                $course->slug,
                $block,
            );
        }

        if (! $this->botConnected($user)) {
            $findings[] = $this->finding(
                self::CODE_NOT_CONNECTED_BOT,
                'Бот не подключён — уведомления об оплате и доступе могут не доходить. Подключите Telegram или ВК.',
                self::ACTION_CONNECT_BOT,
                'Подключить бота',
                (int) $course->id,
                $course->slug,
                $block,
            );
        }

        return $findings;
    }

    /**
     * Profile / bots section summary tile.
     *
     * @return array{
     *   courses_open: int,
     *   bot_connected: bool,
     *   password_ok: bool,
     *   findings: list<array<string, mixed>>,
     *   line: string
     * }
     */
    public function profileSummary(User $user, int $coursesOpen = 0): array
    {
        $bot = $this->botConnected($user);
        $findings = [];

        if (! $bot) {
            $findings[] = $this->finding(
                self::CODE_NOT_CONNECTED_BOT,
                'Бот не подключён.',
                self::ACTION_CONNECT_BOT,
                'Подключить бота',
                null,
                null,
                null,
            );
        }

        // Logged-in student: password works for this session; still offer reset as recovery path.
        $findings[] = $this->finding(
            self::CODE_LOGIN_ISSUE,
            'Не можете войти с другого устройства? Восстановите вход по email заказа.',
            self::ACTION_RESET_PASSWORD,
            'Сбросить пароль / войти по email',
            null,
            null,
            null,
        );

        $botLabel = $bot ? 'бот подключён' : 'бот не подключён';
        $line = sprintf(
            'Доступ: %d курс(ов) · %s · пароль ок',
            $coursesOpen,
            $botLabel,
        );

        return [
            'courses_open' => $coursesOpen,
            'bot_connected' => $bot,
            'password_ok' => true,
            'findings' => $findings,
            'line' => $line,
        ];
    }

    /**
     * Paid primary payments for a course that BlockAccessMaterializer can expand.
     *
     * @return Collection<int, Payment>
     */
    public function materializablePayments(User $user, int $courseId): Collection
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->paid()
            ->where('is_conditional', false)
            ->where(function ($q) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', 'not like', BlockAccessMaterializer::GRANT_PREFIX.'%');
            })
            ->whereNotNull('start_block')
            ->whereNotNull('end_block')
            ->whereColumn('end_block', '>', 'start_block')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function ownedKeys(User $user, int $courseId): array
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->paid()
            ->pluck('tariff')
            ->all();
    }

    /**
     * Same open-gate as StudentController::showCourse / showLesson
     * (free / preview / paid keys / LessonAccessGrant). H2441 playlist reuses this.
     *
     * @param  array<int, string>  $ownedKeys
     */
    public function isLessonAccessible(Lesson $lesson, array $ownedKeys, User $user, int $courseId): bool
    {
        return $this->lessonIsOpen($lesson, $ownedKeys, $user, $courseId);
    }

    public function botConnected(User $user): bool
    {
        return filled($user->telegram_id) || filled($user->vk_id);
    }

    /**
     * @param  array<int, string>  $ownedKeys
     */
    private function lessonIsOpen(Lesson $lesson, array $ownedKeys, User $user, int $courseId): bool
    {
        if ($lesson->is_free || $lesson->is_preview) {
            return true;
        }

        if ($lesson->isUnlockedBy($ownedKeys)) {
            return true;
        }

        return LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('lesson_id', $lesson->id)
            ->active()
            ->exists();
    }

    /**
     * Paid row covers block N via start..end range (or full / block_N key),
     * but Lesson::isUnlockedBy is still false → missing sibling keys.
     *
     * @param  array<int, string>  $ownedKeys
     */
    private function hasPaidCoverageMissingKey(User $user, int $courseId, int $block, array $ownedKeys): bool
    {
        if (in_array('full', $ownedKeys, true) || in_array('block_'.$block, $ownedKeys, true)) {
            return false;
        }

        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->paid()
            ->where(function ($q) use ($block) {
                $q->where(function ($r) use ($block) {
                    $r->whereNotNull('start_block')
                        ->whereNotNull('end_block')
                        ->where('start_block', '<=', $block)
                        ->where('end_block', '>=', $block);
                })->orWhere('tariff', 'full')
                    ->orWhere('tariff', 'block_'.$block);
            })
            ->exists();
    }

    private function hasPendingOrFailedForBlock(User $user, int $courseId, int $block): bool
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', ['pending', 'failed', 'declined', 'canceled', 'cancelled', 'expired'])
            ->where(function ($q) use ($block) {
                $q->where(function ($r) use ($block) {
                    $r->whereNotNull('start_block')
                        ->whereNotNull('end_block')
                        ->where('start_block', '<=', $block)
                        ->where('end_block', '>=', $block);
                })->orWhere('tariff', 'block_'.$block)
                    ->orWhere('tariff', 'full');
            })
            ->exists();
    }

    /**
     * @return array{
     *   code: string,
     *   message: string,
     *   action: string|null,
     *   action_label: string|null,
     *   course_id: int|null,
     *   course_slug: string|null,
     *   block_number: int|null
     * }
     */
    private function finding(
        string $code,
        string $message,
        ?string $action,
        ?string $actionLabel,
        ?int $courseId,
        ?string $courseSlug,
        ?int $block,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'action' => $action,
            'action_label' => $actionLabel,
            'course_id' => $courseId,
            'course_slug' => $courseSlug,
            'block_number' => $block,
        ];
    }
}
