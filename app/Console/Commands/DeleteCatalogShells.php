<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Group;
use App\Services\CatalogShellAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление курсов-оболочек и пустых групп, ОДОБРЕННЫХ аудитом (H3773).
 *
 * H3122 намеренно отгрузил только отчёт: «сперва аудит», поверх условия MG
 * «главное, чтобы не потерялись никакие записи ни при каких обстоятельствах».
 * Это вторая половина, и она сохраняет то же условие тремя способами.
 *
 * 1. **Вердикт не передаётся из отчёта, а пересчитывается здесь.** Команда
 *    заново гоняет {@see CatalogShellAudit} в момент удаления. Отчёт, снятый
 *    вчера (или пять минут назад), к этому моменту мог устареть: на курс успели
 *    записаться, завести тариф, выдать сертификат. Доверять сохранённой строке
 *    отчёта — ровно тот класс ошибки, ради которого аудит и писался.
 * 2. **Идентификаторы обязательны и перечисляются руками.** Нет режима «удали
 *    всё, что безопасно»: список объектов называет человек, а команда лишь
 *    отказывается трогать то, что аудит не одобрил. Пустой `--course`/`--group`
 *    — это отказ, а не «ничего не делать по-тихому».
 * 3. **По умолчанию НИЧЕГО не удаляется.** Без `--apply` печатается план.
 *
 * Связки (`course_user`, `course_group`, `category_course`,
 * `course_slug_aliases`, `group_user`) снимаются явно внутри той же транзакции,
 * а не через `ON DELETE CASCADE`: каскад есть не на каждой таблице, и молчаливое
 * расхождение схемы уронило бы удаление на середине.
 */
class DeleteCatalogShells extends Command
{
    protected $signature = 'catalog:delete-shells
        {--course=* : ID курса-оболочки к удалению}
        {--group=* : ID пустой группы к удалению}
        {--apply : Выполнить удаление (без флага — только план)}';

    protected $description = 'Удалить курсы-оболочки и пустые группы, одобренные аудитом. Вердикт пересчитывается заново; без --apply ничего не делает.';

    /** Связки, которые снимаются вместе с объектом (см. CatalogShellAudit::LINK_TABLES). */
    private const COURSE_LINKS = ['course_user', 'course_group', 'category_course', 'course_slug_aliases'];

    private const GROUP_LINKS = ['group_user', 'course_group'];

    public function handle(CatalogShellAudit $audit): int
    {
        $courseIds = array_map('intval', (array) $this->option('course'));
        $groupIds = array_map('intval', (array) $this->option('group'));

        if ($courseIds === [] && $groupIds === []) {
            $this->error('Не назван ни один объект. Укажите --course= и/или --group= явно: режима «удали всё безопасное» здесь нет by design.');

            return self::FAILURE;
        }

        // Вердикт СЕЙЧАС, а не из отчёта.
        $report = $audit->report();
        $safeCourses = $this->safeIds($report['courses']);
        $safeGroups = $this->safeIds($report['groups']);

        $refused = [];
        foreach ($courseIds as $id) {
            if (! in_array($id, $safeCourses, true)) {
                $refused[] = "курс {$id} — аудит НЕ считает его безопасной оболочкой прямо сейчас";
            }
        }
        foreach ($groupIds as $id) {
            if (! in_array($id, $safeGroups, true)) {
                $refused[] = "группа {$id} — аудит НЕ считает её безопасной пустой группой прямо сейчас";
            }
        }

        if ($refused !== []) {
            $this->error('ОТКАЗ — ни один объект не удалён:');
            foreach ($refused as $line) {
                $this->line('  • '.$line);
            }
            $this->newLine();
            $this->line('Свежий вердикт по каждому объекту: <comment>php artisan catalog:audit-shells</comment>');

            return self::FAILURE;
        }

        $this->plan($courseIds, $groupIds);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Сухой прогон: ничего не удалено. Повторите с --apply.');

            return self::SUCCESS;
        }

        $removed = DB::transaction(function () use ($courseIds, $groupIds): array {
            $counts = [];

            foreach ($courseIds as $id) {
                $counts["курс {$id}"] = $this->purge('course_id', $id, self::COURSE_LINKS)
                    + Course::query()->whereKey($id)->delete();
            }

            foreach ($groupIds as $id) {
                $counts["группа {$id}"] = $this->purge('group_id', $id, self::GROUP_LINKS)
                    + Group::query()->whereKey($id)->delete();
            }

            return $counts;
        });

        $this->newLine();
        foreach ($removed as $what => $rows) {
            $this->line(sprintf('  удалено: %s (строк, включая связки: %d)', $what, $rows));
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово: курсов %d, групп %d. Всего строк %d.',
            count($courseIds),
            count($groupIds),
            array_sum($removed),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function safeIds(array $rows): array
    {
        return array_values(array_map(
            static fn (array $r): int => (int) $r['id'],
            array_filter($rows, static fn (array $r): bool => (bool) $r['safe']),
        ));
    }

    /**
     * Снять связки объекта. Возвращает число удалённых строк.
     *
     * @param  list<string>  $tables
     */
    private function purge(string $column, int $id, array $tables): int
    {
        $rows = 0;

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $rows += DB::table($table)->where($column, $id)->delete();
        }

        return $rows;
    }

    /**
     * @param  list<int>  $courseIds
     * @param  list<int>  $groupIds
     */
    private function plan(array $courseIds, array $groupIds): void
    {
        if ($courseIds !== []) {
            $this->newLine();
            $this->line('<comment>Курсы к удалению</comment>');
            $this->table(
                ['id', 'Курс', 'Записано', 'Связок к снятию'],
                Course::query()->whereIn('id', $courseIds)->orderBy('id')->get()
                    ->map(fn (Course $c): array => [
                        $c->id,
                        mb_substr((string) $c->title, 0, 44),
                        $c->users()->count(),
                        $this->countLinks('course_id', (int) $c->id, self::COURSE_LINKS),
                    ])->all(),
            );
        }

        if ($groupIds !== []) {
            $this->newLine();
            $this->line('<comment>Группы к удалению</comment>');
            $this->table(
                ['id', 'Группа', 'Связок к снятию'],
                Group::query()->whereIn('id', $groupIds)->orderBy('id')->get()
                    ->map(fn (Group $g): array => [
                        $g->id,
                        mb_substr((string) $g->name, 0, 44),
                        $this->countLinks('group_id', (int) $g->id, self::GROUP_LINKS),
                    ])->all(),
            );
        }
    }

    /** @param list<string> $tables */
    private function countLinks(string $column, int $id, array $tables): int
    {
        $n = 0;

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $n += DB::table($table)->where($column, $id)->count();
            }
        }

        return $n;
    }
}
