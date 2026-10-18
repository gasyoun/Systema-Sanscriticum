<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * H6343: soft-delete «фантомного шлейфа» расписания группы — прошедших дат,
 * назначенных на каникулы и не состоявшихся (кейс группы 89: строки #21–#38,
 * 06.06–10.10.26, напоминания уходили по несуществующим занятиям).
 *
 * Безопасность: по умолчанию dry-run (только показать); реальные удаления —
 * только с --apply. Soft-delete обратим (Schedule::withTrashed), живые строки
 * расписания/фида/кабинета отфильтровываются автоматически.
 */
class SoftDeletePhantomScheduleRows extends Command
{
    protected $signature = 'schedule:soft-delete-phantoms
        {group : Группа (id или slug)}
        {--from= : дата начала окна фантомов (YYYY-MM-DD, включительно)}
        {--to= : дата конца окна фантомов (YYYY-MM-DD, включительно)}
        {--apply : реально soft-delete (без флага — только dry-run)}';

    protected $description = 'Soft-delete прошедших «фантомных» строк расписания группы за окно дат (dry-run по умолчанию, H6343)';

    public function handle(): int
    {
        $group = Group::query()
            ->where('id', $this->argument('group'))
            ->orWhere('slug', $this->argument('group'))
            ->first();

        if ($group === null) {
            $this->error('Группа не найдена: '.$this->argument('group'));

            return self::FAILURE;
        }

        $query = $group->schedules()
            ->where('start', '<', now())
            ->orderBy('start');

        if ($this->option('from') !== null) {
            $query->where('start', '>=', $this->option('from').' 00:00:00');
        }
        if ($this->option('to') !== null) {
            $query->where('start', '<=', $this->option('to').' 23:59:59');
        }

        $rows = $query->get();

        $this->info(sprintf(
            'Группа %s (#%d): кандидатов на soft-delete в окне — %d',
            $group->name,
            $group->id,
            $rows->count(),
        ));

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  #%d | %s | %s | reminded_at: %s',
                $row->id,
                $row->title ?: '(без названия)',
                $row->start->format('d.m.Y H:i'),
                $row->reminded_at?->format('d.m.Y H:i') ?? '—',
            ));
        }

        if (! $this->option('apply')) {
            $this->warn('dry-run: ничего не удалено. Для удаления добавьте --apply.');

            return self::SUCCESS;
        }

        $count = 0;
        $rows->each(function (Schedule $row) use (&$count): void {
            $row->delete();
            $count++;
        });

        $this->info("Soft-deleted: {$count}. Живых строк группы теперь: ".$group->schedules()->count().'.');

        return self::SUCCESS;
    }
}
