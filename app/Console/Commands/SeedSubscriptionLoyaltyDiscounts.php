<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\StudentDiscount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H3916: скидка лояльности подписки «в записи» — 5% первого года.
 *
 * Область: уникальные покупатели записных продуктов (бриф 02-09-2026:
 * 112 человек по CRM; 88 в активной базе). Зачёта прошлых покупок нет —
 * только процент на первый год.
 *
 * Канонический вход — экспорт CRM (файл email/user_id по строке).
 * Без файла команда считает приближение по Systema (платежи со статусом
 * paid на курсах «в записи») и ПЕЧАТАЕТ счётчик для сверки — расхождение
 * с 112 ожидаемо и фиксируется в build-отчёте.
 *
 * Механика: StudentDiscount (percent 5) на курсе-членстве; скидку
 * применяет Tariff::calculateFinalPriceForUser, срок гасит expires_at
 * (+1 год). Идемпотентно по (user, course, note).
 */
class SeedSubscriptionLoyaltyDiscounts extends Command
{
    protected $signature = 'membership:seed-loyalty-discounts
        {--file= : CSV/текстовый файл со списком покупателей CRM (email или user_id по строке)}
        {--percent=5 : процент скидки}
        {--months=12 : срок действия скидки в месяцах}
        {--dry-run : показать план без записи}';

    protected $description = 'H3916: 5% первого года подписки «в записи» покупателям записных продуктов';

    public const LOYALTY_NOTE = 'recorded-subscription loyalty (H3916, 5% first year)';

    public function handle(): int
    {
        $course = Course::query()
            ->where('slug', (string) config('membership.club.course_slug', 'club'))
            ->first();

        if ($course === null) {
            $this->error('membership: club course not found');

            return self::FAILURE;
        }

        $percent = max(1, min(50, (int) $this->option('percent')));
        $months = max(1, (int) $this->option('months'));
        $expires = today()->addMonths($months);
        $dryRun = (bool) $this->option('dry-run');

        $file = (string) $this->option('file');
        if ($file !== '') {
            if (! is_file($file)) {
                $this->error("file not found: {$file}");

                return self::FAILURE;
            }

            $identifiers = array_values(array_filter(array_map('trim', file($file) ?: [])));
        } else {
            // Приближение по Systema: платившие за курсы с форматом recorded.
            // НЕ канон: канон — экспорт CRM (112 в брифе 02-09-2026).
            $identifiers = DB::table('payments')
                ->join('courses', 'payments.course_id', '=', 'courses.id')
                ->where('payments.status', 'paid')
                ->where('courses.format', 'recorded')
                ->distinct()
                ->pluck('payments.user_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            $this->warn('NO --file given: using Systema-side approximation (all recorded-course buyers).');
            $this->warn('Canonical CRM list (112, brief 02-09-2026) supersedes this; counts differ by design.');
        }

        $created = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($identifiers as $identifier) {
            $user = ctype_digit($identifier)
                ? User::find((int) $identifier)
                : User::where('email', mb_strtolower($identifier))->first();

            if ($user === null) {
                $missing++;
                $this->line("skip (no user): {$identifier}");

                continue;
            }

            $exists = StudentDiscount::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('note', self::LOYALTY_NOTE)
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $this->line("discount: user {$user->id} ({$user->email}) -{$percent}% until {$expires->toDateString()}");
            if (! $dryRun) {
                StudentDiscount::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'type' => StudentDiscount::TYPE_PERCENT,
                    'value' => $percent,
                    'is_active' => true,
                    'expires_at' => $expires,
                    'note' => self::LOYALTY_NOTE,
                ]);
            }
            $created++;
        }

        $this->info("loyalty discounts: created={$created} skipped_existing={$skipped} missing_users={$missing}"
            .($dryRun ? ' (DRY-RUN)' : ''));

        return self::SUCCESS;
    }
}
