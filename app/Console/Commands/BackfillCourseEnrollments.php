<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCourseEnrollments extends Command
{
    protected $signature = 'courses:backfill-enrollments {--dry-run : Только показать, ничего не записывать}';

    protected $description = 'Создаёт недостающие записи «Обучается на курсах» (course_user, статус «Записался») для студентов с успешной оплатой курса.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Уникальные пары (студент, курс) из успешных платежей.
        // Депозиты и системные расходы доступ не открывают — исключаем по tariff
        // (см. Payment::isDeposit() / isExpense()).
        $pairs = DB::table('payments')
            ->whereIn('status', ['paid', 'success'])
            ->whereNotIn('tariff', ['deposit', 'Расход'])
            ->whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->select('user_id', 'course_id')
            ->distinct()
            ->get();

        // Уже существующие связи — чтобы не трогать ручные статусы.
        $existing = DB::table('course_user')
            ->select('user_id', 'course_id')
            ->get()
            ->map(fn ($r) => $r->user_id.':'.$r->course_id)
            ->flip();

        $now = now();
        $toInsert = [];

        foreach ($pairs as $p) {
            $key = $p->user_id.':'.$p->course_id;

            if ($existing->has($key)) {
                continue;
            }

            // Ключ массива гасит возможные дубли в самой выборке.
            $toInsert[$key] = [
                'user_id' => $p->user_id,
                'course_id' => $p->course_id,
                'status' => 'Записался',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $toInsert = array_values($toInsert);

        $this->info('Оплативших пар (студент × курс): '.count($pairs));
        $this->info(($dryRun ? '[dry-run] ' : '').'К созданию записей «Записался»: '.count($toInsert));

        if ($dryRun || empty($toInsert)) {
            return self::SUCCESS;
        }

        $created = 0;
        // insertOrIgnore — backstop на случай гонки с unique(user_id, course_id).
        foreach (array_chunk($toInsert, 500) as $chunk) {
            $created += DB::table('course_user')->insertOrIgnore($chunk);
        }

        $this->info("Создано записей: {$created}.");

        return self::SUCCESS;
    }
}
