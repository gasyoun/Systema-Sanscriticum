<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Membership\FreeTierLessonGranter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Месячная выдача урока бесплатного уровня «Свободный» (H2644).
 *
 * Два режима, один и тот же движок:
 *  - демон (планировщик): `membership:grant-free-lesson --apply`. **С H2915 он
 *    доставляет КОГОРТУ ИЗ ФАЙЛА** (`membership.free_tier.cohort_file`): список
 *    пишет человек, демон только раздаёт — и потому имеет право писать. Файл
 *    недоступен или пуст → команда возвращает FAILURE и НЕ откатывается на
 *    авто-подбор: опечатка в пути не должна превращать адресную раздачу в
 *    раздачу всем. Без файла работает H2899: авто-подбор не знает про давность
 *    (923 человека против 372 в когорте, которую документы называют «350»),
 *    поэтому писать ему нельзя без `FREE_TIER_DAEMON_APPLY=true`;
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

        $source = 'кампания';

        if (! $campaign) {
            // H2915. Когорта демона задана файлом — список пишет человек, демон
            // только доставляет. Это возвращает демону работу, не возвращая ему
            // право решать, кому дарить.
            $cohortPath = $this->cohortPath();

            if ($cohortPath !== null) {
                $users = $this->readCohort($cohortPath);

                // ФЕЙЛ-КЛОУЗД, и это главный инвариант всей правки: путь с
                // опечаткой, снесённый файл, файл без прав чтения у www-data —
                // всё это НЕ ДОЛЖНО откатываться в авто-подбор, иначе одна
                // неверная буква в пути превращает адресную раздачу в раздачу
                // всем 923. Возвращаем FAILURE, чтобы ScheduleFailureSignal
                // поднял тревогу: молчаливо не выдать — тоже плохо.
                if ($users === null) {
                    $this->error('КОГОРТА НЕДОСТУПНА: '.$cohortPath);
                    $this->line('  Демон НЕ откатывается на авто-подбор — это было бы 923 человека вместо списка.');
                    $this->line('  Файл читает www-data (планировщик), поэтому он живёт в storage/app, а не в /root.');
                    $this->line('  Проверить: sudo -u www-data test -r '.$cohortPath);

                    return self::FAILURE;
                }

                $source = 'демон/файл';
                $limit = max(1, (int) $this->option('limit'));
                if ($users->count() > $limit) {
                    // Явно, а не молча: усечённая когорта читается как «выдали
                    // всем», если про обрезку не сказать.
                    $this->warn('Когорта '.$users->count().' человек, за прогон берём '.$limit
                        .' — остальные достанутся следующим прогонам (--limit).');
                    $users = $users->take($limit);
                }
            } else {
                // H2899. Файла нет — значит демон выбирает сам, а его правило не
                // знает про давность: `eligibleUserIds()` сортирует по «давно не
                // платил» и берёт верхние N. На проде это 923 человека против 372
                // в той двенадцатимесячной когорте, которую документы называют
                // «350». Раздача в 2,5 раза шире написанного, по 200 в сутки и
                // молча, — не то, что должен делать флаг, названный именем
                // кампании. Отчёт остаётся всегда, чтобы демон не стал тишиной.
                if ($apply && ! (bool) config('membership.free_tier.daemon_apply', false)) {
                    $this->warn('РЕЖИМ ДЕМОНА: авто-выдача запрещена (membership.free_tier.daemon_apply = false) — только отчёт.');
                    $this->line('  Задайте когорту файлом: FREE_TIER_COHORT_FILE (по умолчанию storage/app/membership/free_tier_cohort.txt),');
                    $this->line('  или доставьте кампанией: --users-file=<файл> --reason=<метка> --apply.');
                    $this->line('  Авто-подбор без списка: FREE_TIER_DAEMON_APPLY=true (кому дарить — решение человека, не сортировки).');
                    $apply = false;
                }

                $source = 'демон/авто';
                $ids = $granter->eligibleUserIds(max(1, (int) $this->option('limit')));
                $users = User::query()->whereIn('id', $ids)->get();
            }
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
        $this->info(($apply ? 'ВЫДАНО' : 'СУХОЙ ПРОГОН').' · '.$source.' · '.implode(' · ', $summary));

        if (! $apply) {
            $this->line('Записать: повторить с --apply');
        }

        return self::SUCCESS;
    }

    /**
     * Настроенный путь к файлу когорты, или NULL — файла не задано, работает
     * поведение H2899 (авто-подбор без права писать).
     *
     * Относительный путь разрешается от storage/app намеренно: планировщик
     * ходит под www-data, и storage — единственное место, куда у него заведомо
     * есть доступ. Абсолютный путь принимается для нестандартных установок.
     */
    private function cohortPath(): ?string
    {
        $configured = trim((string) config('membership.free_tier.cohort_file', ''));
        if ($configured === '') {
            return null;
        }

        $isAbsolute = str_starts_with($configured, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;

        return $isAbsolute ? $configured : storage_path('app/'.ltrim($configured, '/'));
    }

    /**
     * Пользователи из файла когорты, или NULL — файл недоступен/пуст.
     *
     * NULL здесь означает «не смог», а НЕ «никого не нашёл»: вызывающий обязан
     * различать эти два случая, иначе нечитаемый файл тихо превратится в
     * «кандидатов нет» и раздача просто перестанет происходить незаметно.
     *
     * @return Collection<int, User>|null
     */
    private function readCohort(string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $tokens = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $tokens[] = $line;
            }
        }

        if ($tokens === []) {
            // Пустой файл — тоже отказ: «когорта задана и пуста» почти всегда
            // означает сорванную выгрузку, а не решение никому не дарить.
            return null;
        }

        $users = $this->usersByTokens(array_values(array_unique($tokens)));

        $missing = count(array_unique($tokens)) - $users->count();
        if ($missing > 0) {
            $this->warn('В когорте не найдено пользователей: '.$missing.' из '.count(array_unique($tokens)).' — проверьте файл.');
        }

        return $users;
    }

    /**
     * Явно заданный список (режим кампании) или NULL — значит режим демона.
     * Принимаем и id, и email: список для кампании приходит из выгрузки, где
     * человек опознан почтой, а не первичным ключом.
     *
     * @return Collection<int, User>|null
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

        $users = $this->usersByTokens($tokens);

        $missing = count($tokens) - $users->count();
        if ($missing > 0) {
            $this->warn('Не найдено пользователей: '.$missing.' из '.count($tokens).' — проверьте список.');
        }

        return $users;
    }

    /**
     * Разрешение списка «id или email» в пользователей — общее для кампании
     * (--user/--users-file) и для файла когорты демона. Общее НАМЕРЕННО: две
     * копии этой логики разошлись бы на нормализации почты, и один и тот же
     * список давал бы разный охват в зависимости от того, каким путём он попал
     * в команду.
     *
     * @param  list<string>  $tokens
     * @return Collection<int, User>
     */
    private function usersByTokens(array $tokens)
    {
        $ids = array_values(array_filter($tokens, static fn ($t) => ctype_digit((string) $t)));
        $emails = array_values(array_filter($tokens, static fn ($t) => ! ctype_digit((string) $t)));

        return User::query()
            ->when($ids !== [], fn ($q) => $q->orWhereIn('id', $ids))
            ->when($emails !== [], fn ($q) => $q->orWhereIn('email', array_map(
                static fn ($e) => User::normalizeEmail((string) $e),
                $emails,
            )))
            ->get();
    }
}
