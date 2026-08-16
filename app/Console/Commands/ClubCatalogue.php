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
        {--key= : объём доступа — full (по умолчанию) или block_N / block_N_hH (H2886)}
        {--remove : убрать из полки вместо добавления}
        {--apply : записать (без флага — сухой прогон)}';

    protected $description = 'Клуб: показать/проставить courses.club_included + объём доступа для полки записей (H2644, H2886).';

    public function handle(ClubEntitlement $entitlement): int
    {
        $apply = (bool) $this->option('apply');
        $remove = (bool) $this->option('remove');
        $explicit = array_filter(array_map('trim', (array) $this->option('course')));

        // Объём доступа. Валидируем ФОРМУ здесь, а не при чтении: опечатка
        // «block1» вместо «block_1» дала бы ключ, который не совпадает ни с
        // одним Lesson::unlockingKeys, то есть курс в полке и ноль открытых
        // уроков — ровно тот молчаливый провал, ради которого написан H2886.
        $key = trim((string) $this->option('key'));
        if ($key !== '' && $key !== 'full' && preg_match('/^block_\d+(_h\d+)?$/', $key) !== 1) {
            $this->error('--key должен быть full, block_N или block_N_hH; получено: '.$key);

            return self::FAILURE;
        }
        if ($key !== '' && $remove) {
            $this->error('--key бессмысленен вместе с --remove: снятие с полки обнуляет объём.');

            return self::FAILURE;
        }
        if ($key !== '' && $explicit === []) {
            $this->error('--key требует явного --course=: назначать объём авто-подбором нельзя.');

            return self::FAILURE;
        }

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
        // Объём тоже считается изменением: курс может УЖЕ лежать в полке, но с
        // другим ключом, и молча оставить старый объём — значит отдать за ₽1 500
        // не то, что решили.
        $targetKey = $remove ? null : ($key === '' ? null : $key);
        $changing = $courses->filter(function (Course $c) use ($target, $targetKey, $key): bool {
            if ((bool) $c->club_included !== $target) {
                return true;
            }

            return $key !== '' && (string) ($c->club_access_key ?? '') !== (string) $targetKey;
        });

        if ($changing->isEmpty()) {
            // H2886 / FINDINGS §418: НЕ схлопывать «нечего менять» и «нечего
            // выбрать» в одну строку. Пустая выборка при авто-подборе — это не
            // «уже сделано», это «команда структурно не могла ничего предложить»,
            // и раньше обе читались как зелёный успех.
            if ($courses->isEmpty()) {
                $this->warn('НЕЧЕГО ВЫБРАТЬ: под критерий не подошёл НИ ОДИН курс — это не «уже набрано».');
                if ($explicit === []) {
                    $this->line('  Авто-подбор идёт через Course::sellsRecordings(), которому нужны ОДНОВРЕМЕННО:');
                    $this->line('    features.course_recordings_sales = '
                        .var_export((bool) config('features.course_recordings_sales', false), true)
                        .' и is_completed=true у курса ('
                        .Course::query()->where('is_active', true)->where('is_completed', true)->count()
                        .' из '.Course::query()->where('is_active', true)->count().' активных).');
                    $this->line('  Рабочая форма — явный список: --course=<id|slug> --course=… [--key=block_N] --apply');
                }

                return self::FAILURE;
            }

            $this->info('Менять нечего: подходящих курсов '.$courses->count()
                .', все уже '.($target ? 'в полке' : 'вне полки')
                .($key !== '' ? ' с объёмом '.$key : '').'.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'название', 'сейчас', 'станет'],
            $changing->map(fn (Course $c): array => [
                $c->id,
                (string) $c->slug,
                mb_substr((string) $c->title, 0, 48),
                $c->club_included ? 'в полке ('.$entitlement->accessKeyFor($c).')' : '—',
                $target ? 'в полке ('.($targetKey ?? 'full').')' : '—',
            ])->values()->all(),
        );

        if ($apply) {
            $update = ['club_included' => $target];
            // При снятии с полки объём обнуляем: иначе оставшийся block_N тихо
            // воскреснет, когда курс вернут в полку без --key.
            if ($remove || $key !== '') {
                $update['club_access_key'] = $targetKey;
            }
            Course::query()->whereIn('id', $changing->pluck('id'))->update($update);
            $this->info(($target ? 'ДОБАВЛЕНО В ПОЛКУ: ' : 'УБРАНО ИЗ ПОЛКИ: ').$changing->count()
                .($target ? ', объём '.($targetKey ?? 'full') : ''));
        } else {
            $this->info('СУХОЙ ПРОГОН: изменилось бы '.$changing->count().' курс(ов). Записать: --apply');
        }

        return self::SUCCESS;
    }
}
