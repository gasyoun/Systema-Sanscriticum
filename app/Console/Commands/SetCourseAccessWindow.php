<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseAccessWindow;
use App\Models\User;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * H4456 — управление окном доступа «студент × курс» (course_access_windows).
 *
 * Рулинг MG 09-09-2026: вечный доступ к курсам Парибка — только именное
 * исключение; остальным — окно с датой отсечки. Окно НЕ трогает платежи
 * (деньги): оно лишь перестаёт пускать реальные ключи курса после ends_at.
 *
 *   php artisan access:set-window 6476 327 --until="2026-09-27 23:59" --reason="MG 09-09: 18 дней" --by=1
 *   php artisan access:set-window 6476 327 --forever          # вечное именное исключение
 *   php artisan access:set-window 6476 327 --revoke           # снять окно
 */
final class SetCourseAccessWindow extends Command
{
    protected $signature = 'access:set-window
        {user : id студента}
        {course : id курса}
        {--until= : дата/время отсечки (Y-m-d[ H:i], таймзона приложения)}
        {--forever : вечный доступ по именному исключению (ends_at = NULL)}
        {--revoke : снять окно (вернуть прежнее поведение)}
        {--reason= : причина (в аудиторскую строку)}
        {--by= : id администратора, поставившего окно}';

    protected $description = 'H4456: окно доступа (course_access_windows) — отсечка реальных ключей курса по дате, без правки платежей';

    public function handle(): int
    {
        $userId = (int) $this->argument('user');
        $courseId = (int) $this->argument('course');

        if (! User::query()->whereKey($userId)->exists()) {
            $this->error("Пользователь {$userId} не найден.");

            return self::FAILURE;
        }

        if (! Course::query()->whereKey($courseId)->exists()) {
            $this->error("Курс {$courseId} не найден.");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $deleted = CourseAccessWindow::clearFor($userId, $courseId);
            $this->info($deleted > 0
                ? "Окно снято (удалено строк: {$deleted})."
                : 'Окна не было — делать нечего.');

            return self::SUCCESS;
        }

        if ($this->option('forever')) {
            $endsAt = null;
        } else {
            $raw = trim((string) $this->option('until'));
            if ($raw === '') {
                $this->error('Укажите --until="Y-m-d[ H:i]" или --forever (или --revoke).');

                return self::FAILURE;
            }

            try {
                $endsAt = Carbon::parse($raw);
            } catch (InvalidFormatException $e) {
                $this->error("Не разобрать дату: {$raw}");

                return self::FAILURE;
            }
        }

        $window = CourseAccessWindow::setUntil(
            $userId,
            $courseId,
            $endsAt,
            filled((string) $this->option('reason')) ? (string) $this->option('reason') : null,
            filled((string) $this->option('by')) ? (int) $this->option('by') : null,
        );

        $this->info(json_encode([
            'user_id' => $window->user_id,
            'course_id' => $window->course_id,
            'ends_at' => $window->ends_at?->format('Y-m-d H:i:s T'),
            'active' => $window->isActive(),
            'reason' => $window->reason,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
