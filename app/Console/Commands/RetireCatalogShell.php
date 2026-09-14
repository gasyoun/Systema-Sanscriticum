<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\CatalogShellRetirement;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Свести курс-оболочку на живой курс той же семьи (H3807).
 *
 * По умолчанию — сухой прогон: печатает план и выходит. Пишет только с --apply.
 * Не удаляет ничего и никогда: удаление оболочки — отдельный проход после того,
 * как человек посмотрит на результат этой команды.
 */
class RetireCatalogShell extends Command
{
    protected $signature = 'catalog:retire-shell
        {course : id или slug курса-оболочки}
        {--into= : id или slug живого курса той же семьи}
        {--apply : Выполнить (без флага — только показать план)}';

    protected $description = 'Свести курс-оболочку на живой курс семьи: записи, скрытие с витрины, алиас слага. Ничего не удаляет.';

    public function handle(CatalogShellRetirement $retirement): int
    {
        $shell = $this->resolve((string) $this->argument('course'));
        if ($shell === null) {
            return self::FAILURE;
        }

        $into = (string) ($this->option('into') ?? '');
        if ($into === '') {
            $this->error('Не указан --into: живой курс семьи, на который сводим.');

            return self::FAILURE;
        }

        $target = $this->resolve($into);
        if ($target === null) {
            return self::FAILURE;
        }

        try {
            $plan = $this->option('apply')
                ? $retirement->apply($shell, $target)
                : $retirement->plan($shell, $target);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($plan, (bool) $this->option('apply'));

        return self::SUCCESS;
    }

    private function resolve(string $ref): ?Course
    {
        $course = ctype_digit($ref)
            ? Course::query()->find((int) $ref)
            : Course::query()->where('slug', $ref)->first();

        if ($course === null) {
            $this->error("Курс не найден: {$ref}");
        }

        return $course;
    }

    /** @param array<string, mixed> $plan */
    private function render(array $plan, bool $applied): void
    {
        $verb = $applied ? 'Сделано' : 'План (ничего не записано)';

        $this->newLine();
        $this->line("<comment>{$verb}</comment>");
        $this->line(sprintf(
            '  оболочка: %d «%s» (/k/%s)',
            $plan['shell']['id'], $plan['shell']['title'], $plan['shell']['slug'],
        ));
        $this->line(sprintf(
            '  живой курс: %d «%s» (/k/%s)',
            $plan['target']['id'], $plan['target']['title'], $plan['target']['slug'],
        ));

        $covered = $plan['enrolments']['covered'];
        $toMove = $plan['enrolments']['to_move'];

        $this->newLine();
        $this->line(sprintf('  записи уже на живом курсе: %d%s', count($covered), $covered === [] ? '' : ' ('.implode(', ', $covered).')'));
        $this->line(sprintf(
            '  %s на живой курс: %d%s',
            $applied ? 'добавлено' : 'будет добавлено',
            count($toMove),
            $toMove === [] ? '' : ' ('.implode(', ', $toMove).')',
        ));
        $this->line('  записи с оболочки не отвязываются — след сохраняется до удаления курса');

        $this->line($plan['visibility']['change']
            ? '  витрина: '.($applied ? 'скрыт' : 'будет скрыт').' (is_visible → false)'
            : '  витрина: уже скрыт, менять нечего');

        if ($plan['alias']['create']) {
            $this->line(sprintf(
                '  алиас: /k/%s %s на курс %d (301 после будущего удаления)',
                $plan['alias']['slug'],
                $applied ? 'заведён' : 'будет заведён',
                $plan['target']['id'],
            ));
        } else {
            $this->line('  алиас: не трогаем — '.($plan['alias']['reason'] ?? 'причина не указана'));
        }

        $this->newLine();
        if ($applied) {
            $this->info('Курс НЕ удалён. Удаление — отдельный проход после проверки человеком.');
        } else {
            $this->info('Сухой прогон. Повторите с --apply, чтобы выполнить.');
        }
    }
}
