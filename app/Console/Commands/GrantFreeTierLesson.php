<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Membership\FreeTierLessonGranter;
use Illuminate\Console\Command;

/**
 * Месячная выдача урока бесплатного уровня «Свободный» (H2644).
 *
 * Два режима, один и тот же движок:
 *  - демон (планировщик): `membership:grant-free-lesson --apply` — берёт
 *    кандидатов сам, начиная с самых уснувших;
 *  - КАМПАНИЯ: `--user=` / `--users-file=` + `--reason=` — выдаёт конкретному
 *    списку с собственной меткой. Это условие приёмки хендоффа: D6 = вариант
 *    «а» сделал бесплатный уровень механизмом доставки для всех 350 уснувших
 *    плательщиков, и H2566 обязан уметь его драйвить, иначе кампании пришлось
 *    бы строить вторую точку входа к тому же гранту.
 *
 * Сухой прогон — ПО УМОЛЧАНИЮ. Пишет только `--apply`.
 */
class GrantFreeTierLesson extends Command
{
    protected $signature = 'membership:grant-free-lesson
        {--user=* : конкретные user_id или email (режим кампании)}
        {--users-file= : файл со списком id/email, по одному в строке}
        {--reason= : метка в lesson_access_grants.reason (по умолчанию из config)}
        {--limit=200 : сколько кандидатов взять в режиме демона}
        {--apply : записать гранты (без флага — сухой прогон)}';

    protected $description = 'Бесплатный уровень: выдать одному члену один урок из ранее оплаченного курса на 30 дней (H2644).';

    public function handle(FreeTierLessonGranter $granter): int
    {
        $apply = (bool) $this->option('apply');
        $reason = $this->option('reason') ? (string) $this->option('reason') : null;

        if (! $granter->enabled()) {
            $this->warn('Флаг features.membership_free_tier ВЫКЛЮЧЕН — только отчёт, ничего не пишем.');
            $apply = false;
        }

        $users = $this->resolveUsers();
        $campaign = $users !== null;

        if (! $campaign) {
            $ids = $granter->eligibleUserIds(max(1, (int) $this->option('limit')));
            $users = User::query()->whereIn('id', $ids)->get();
        }

        if ($users->isEmpty()) {
            $this->info('Кандидатов нет.');

            return self::SUCCESS;
        }

        $rows = [];
        $counts = [];
        foreach ($users as $user) {
            $row = $granter->grantFor($user, $apply, $reason);
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            $rows[] = [
                $row['user_id'],
                (string) $user->email,
                $row['status'],
                $row['lesson_id'] ?? '—',
                $row['lesson_title'] ? mb_substr($row['lesson_title'], 0, 40) : '—',
                $row['expires_at'] ?? '—',
            ];
        }

        $this->table(['user', 'email', 'статус', 'урок', 'название', 'до'], $rows);

        $summary = [];
        foreach ($counts as $status => $n) {
            $summary[] = $status.'='.$n;
        }
        $this->info(($apply ? 'ВЫДАНО' : 'СУХОЙ ПРОГОН').' · '.($campaign ? 'кампания' : 'демон').' · '.implode(' · ', $summary));

        if (! $apply) {
            $this->line('Записать: повторить с --apply');
        }

        return self::SUCCESS;
    }

    /**
     * Явно заданный список (режим кампании) или NULL — значит режим демона.
     * Принимаем и id, и email: список для кампании приходит из выгрузки, где
     * человек опознан почтой, а не первичным ключом.
     *
     * @return \Illuminate\Support\Collection<int, User>|null
     */
    private function resolveUsers()
    {
        $tokens = array_map('trim', (array) $this->option('user'));

        $file = $this->option('users-file');
        if ($file) {
            if (! is_file((string) $file)) {
                $this->error('Файл не найден: '.$file);

                return collect();
            }
            foreach (preg_split('/\R/', (string) file_get_contents((string) $file)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $tokens[] = $line;
                }
            }
        }

        $tokens = array_values(array_filter(array_unique($tokens), static fn ($t) => $t !== ''));
        if ($tokens === []) {
            return null;
        }

        $ids = array_values(array_filter($tokens, static fn ($t) => ctype_digit((string) $t)));
        $emails = array_values(array_filter($tokens, static fn ($t) => ! ctype_digit((string) $t)));

        $users = User::query()
            ->when($ids !== [], fn ($q) => $q->orWhereIn('id', $ids))
            ->when($emails !== [], fn ($q) => $q->orWhereIn('email', array_map(
                static fn ($e) => User::normalizeEmail((string) $e),
                $emails,
            )))
            ->get();

        $missing = count($tokens) - $users->count();
        if ($missing > 0) {
            $this->warn('Не найдено пользователей: '.$missing.' из '.count($tokens).' — проверьте список.');
        }

        return $users;
    }
}
