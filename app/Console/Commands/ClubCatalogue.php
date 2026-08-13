<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\Membership\ClubEntitlement;
use Illuminate\Console\Command;

/**
 * Набор клубной полки — простановка `courses.club_included` (H2644).
 *
 * Полка НЕ выводится автоматически из «курс активен»: клуб продаёт ЗАПИСИ, и
 * курс живого потока, попавший в полку по недосмотру, отдал бы за ₽1 500 то,
 * что стоит ₽6 000. Поэтому колонка по умолчанию false у всех 128 курсов, а
 * эта команда лишь ПРЕДЛАГАЕТ кандидатов по уже существующему в системе
 * признаку «продаёт записи» (Course::sellsRecordings(): курс завершён или у
 * него есть активный тариф-запись) — и без --apply ничего не меняет.
 */
class ClubCatalogue extends Command
{
    protected $signature = 'membership:club-catalogue
        {--course=* : конкретные id или slug курсов (вместо авто-подбора)}
        {--remove : убрать из полки вместо добавления}
        {--apply : записать (без флага — сухой прогон)}';

    protected $description = 'Клуб: показать/проставить courses.club_included для полки записей (H2644).';

    public function handle(ClubEntitlement $entitlement): int
    {
        $apply = (bool) $this->option('apply');
        $remove = (bool) $this->option('remove');
        $explicit = array_filter(array_map('trim', (array) $this->option('course')));

        $query = Course::query();

        if ($explicit !== []) {
            $ids = array_values(array_filter($explicit, static fn ($t) => ctype_digit((string) $t)));
            $slugs = array_values(array_filter($explicit, static fn ($t) => ! ctype_digit((string) $t)));
            $query->where(fn ($q) => $q
                ->when($ids !== [], fn ($w) => $w->orWhereIn('id', $ids))
                ->when($slugs !== [], fn ($w) => $w->orWhereIn('slug', $slugs)));
            $courses = $query->get();
        } else {
            // Авто-подбор — только для добавления: «что убрать» не выводится из
            // признака записи, это всегда явное решение человека.
            if ($remove) {
                $this->error('--remove требует явного --course= — массового снятия полки нет намеренно.');

                return self::FAILURE;
            }
            $courses = $query->where('is_active', true)->get()
                ->filter(fn (Course $c) => $c->sellsRecordings());
        }

        // Курс-членство в собственную полку не входит: он товар, а не содержимое.
        $club = $entitlement->clubCourse();
        if ($club instanceof Course) {
            $courses = $courses->reject(fn (Course $c) => (int) $c->id === (int) $club->id);
        }

        $target = ! $remove;
        $changing = $courses->filter(fn (Course $c) => (bool) $c->club_included !== $target);

        if ($changing->isEmpty()) {
            $this->info('Менять нечего: подходящих курсов '.$courses->count()
                .', все уже '.($target ? 'в полке' : 'вне полки').'.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'название', 'сейчас', 'станет'],
            $changing->map(fn (Course $c): array => [
                $c->id,
                (string) $c->slug,
                mb_substr((string) $c->title, 0, 48),
                $c->club_included ? 'в полке' : '—',
                $target ? 'в полке' : '—',
            ])->values()->all(),
        );

        if ($apply) {
            Course::query()->whereIn('id', $changing->pluck('id'))->update(['club_included' => $target]);
            $this->info(($target ? 'ДОБАВЛЕНО В ПОЛКУ: ' : 'УБРАНО ИЗ ПОЛКИ: ').$changing->count());
        } else {
            $this->info('СУХОЙ ПРОГОН: изменилось бы '.$changing->count().' курс(ов). Записать: --apply');
        }

        return self::SUCCESS;
    }
}
