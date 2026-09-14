<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\CatalogShellRetirement;
use Illuminate\Console\Command;

/**
 * Оживить осиротевший слаг: направить его на существующий курс (H3807).
 *
 * Нужна там, где курс уже удалён, а ссылки на него остались. Ровно этот случай
 * случился 31-08-2026: `catalog:delete-shells` снёс курсы 421 и 430 вместе с их
 * строками в `course_slug_aliases`, и `/k/karaki-po-panini-2025-2026-v-zapisi`
 * стал отдавать 404. Сама причина закрыта в `catalog:delete-shells`
 * (слаг переселяется до удаления), но уже мёртвые ссылки чинить нечем — этим.
 *
 * Чужое не перевешивает: слаг, который уже канон другого курса или алиас
 * третьего, остаётся как есть, и команда говорит об этом вслух.
 */
class AliasCourseSlug extends Command
{
    protected $signature = 'catalog:alias-slug
        {slug : Осиротевший слаг без /k/}
        {--into= : id или slug курса, на который его направить}
        {--apply : Выполнить (без флага — только показать)}';

    protected $description = 'Направить осиротевший слаг на существующий курс (301 вместо 404). Ничего не удаляет.';

    public function handle(CatalogShellRetirement $retirement): int
    {
        $slug = trim((string) $this->argument('slug'));
        $into = trim((string) ($this->option('into') ?? ''));

        if ($into === '') {
            $this->error('Не указан --into: курс, на который направляем слаг.');

            return self::FAILURE;
        }

        $target = ctype_digit($into)
            ? Course::query()->find((int) $into)
            : Course::query()->where('slug', $into)->first();

        if ($target === null) {
            $this->error("Курс не найден: {$into}");

            return self::FAILURE;
        }

        $resolved = Course::resolveBySlug($slug);
        if ($resolved !== null) {
            $this->warn(sprintf(
                'Слаг /k/%s уже ведёт на курс %d «%s» — не трогаем.',
                $slug, $resolved->id, $resolved->title,
            ));

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info(sprintf('План: /k/%s → курс %d «%s». Повторите с --apply.', $slug, $target->id, $target->title));

            return self::SUCCESS;
        }

        if (! $retirement->adoptSlug($slug, $target)) {
            $this->error("Слаг /k/{$slug} занят другим курсом — молча не перевешиваем.");

            return self::FAILURE;
        }

        $this->info(sprintf('Готово: /k/%s → курс %d «%s» (301).', $slug, $target->id, $target->title));

        return self::SUCCESS;
    }
}
