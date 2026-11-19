<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\Seo\CourseContentAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Факт-лист для редактора страницы `/k/` (H7094, 19-10-2026).
 *
 * Отдаёт только факты из БД: преподаватель, формат, часы, занятия и блоки с
 * датами, тарифы, число оплат. Ни одной сочинённой фразы — текст пишет человек,
 * а команда убирает research-часть, чтобы 120–200 слов занимали десять минут.
 */
class SeoContentFacts extends Command
{
    protected $signature = 'catalog:seo-content-facts
        {slug? : slug курса; без него — очередь на редактуру}
        {--limit=0 : сколько курсов из очереди (0 — вся очередь)}
        {--format=md : md или json}';

    protected $description = 'Факт-лист для написания уникального описания курса (без генерации прозы)';

    public function handle(CourseContentAudit $audit): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['md', 'json'], true)) {
            $this->error('--format: только md или json.');

            return self::FAILURE;
        }

        $slug = $this->argument('slug');

        if (is_string($slug) && $slug !== '') {
            $course = $audit->query()->where('slug', $slug)->first();

            if (! $course instanceof Course) {
                $this->error("Курс {$slug} не найден в sitemap-скоупе (visible + public + own card).");

                return self::FAILURE;
            }

            $courses = collect([$course]);
        } else {
            $courses = $audit->priorityQueue(max(0, (int) $this->option('limit')));

            if ($courses->isEmpty()) {
                $this->info('Очередь пуста: у всех курсов описание не короче '.CourseContentAudit::SHORT_DESCRIPTION.' символов.');

                return self::SUCCESS;
            }
        }

        // Коллекция после filter()/values() может быть базовой — грузим каждую
        // модель отдельно, у неё load()/loadCount() есть всегда.
        $courses->each(static function (Course $course): void {
            $course->load(['teacher', 'lessons', 'tariffs'])->loadCount('payments');
        });

        $facts = $courses->values()->map(
            fn (Course $course, int $i): array => $this->factsFor($course, $i + 1),
        )->all();

        if ($format === 'json') {
            $this->line(json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($facts as $fact) {
            $this->renderMarkdown($fact);
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function factsFor(Course $course, int $rank): array
    {
        $lessons = $course->lessons
            ->sortBy([['block_number', 'asc'], ['block_half', 'asc'], ['sort_order', 'asc']])
            ->values();

        $blocks = $lessons
            ->groupBy(static fn ($lesson): int => (int) ($lesson->block_number ?? 0))
            ->map(static function (Collection $group, int $number): array {
                $dates = $group->pluck('lesson_date')->filter()->sort()->values();

                return [
                    'block' => $number,
                    'lessons' => $group->count(),
                    'from' => $dates->first()?->format('d.m.Y'),
                    'to' => $dates->last()?->format('d.m.Y'),
                ];
            })
            ->values()
            ->all();

        $tariffs = $course->tariffs
            ->map(static fn ($tariff): array => [
                'title' => (string) $tariff->title,
                'price' => $tariff->price !== null ? (float) $tariff->price : null,
                'active' => (bool) $tariff->is_active,
            ])
            ->values()
            ->all();

        $description = trim((string) $course->description);

        return [
            'rank' => $rank,
            'title' => (string) $course->title,
            'slug' => (string) $course->slug,
            'url' => route('shop.course.show', $course->slug),
            'teacher' => $course->teacher?->name,
            'format' => $course->format,
            'hours' => $course->hours_count,
            'lessons' => $lessons->count(),
            'blocks' => $blocks,
            'tariffs' => $tariffs,
            'description_chars' => mb_strlen($description),
            'payments' => (int) ($course->payments_count ?? 0),
        ];
    }

    /** @param array<string, mixed> $fact */
    private function renderMarkdown(array $fact): void
    {
        $this->newLine();
        $this->line(sprintf('# %d. %s', $fact['rank'], $fact['title']));
        $this->line(sprintf('- Slug: `%s`', $fact['slug']));
        $this->line(sprintf('- URL: %s', $fact['url']));
        $this->line(sprintf('- Преподаватель: %s', $fact['teacher'] ?? 'нет данных'));
        $this->line(sprintf('- Формат: %s', $fact['format'] ?? 'нет данных'));
        $this->line(sprintf('- Часов: %s | занятий: %d', $fact['hours'] ?? 'нет данных', $fact['lessons']));
        $this->line(sprintf('- Оплаты: %d | описание: %d символов', $fact['payments'], $fact['description_chars']));

        if ($fact['blocks'] !== []) {
            $this->newLine();
            $this->line('## Блоки');
            $this->table(
                ['Блок', 'Занятий', 'С', 'По'],
                array_map(static fn (array $b): array => [
                    $b['block'] === 0 ? '—' : $b['block'],
                    $b['lessons'],
                    $b['from'] ?? '—',
                    $b['to'] ?? '—',
                ], $fact['blocks']),
            );
        }

        if ($fact['tariffs'] !== []) {
            $this->newLine();
            $this->line('## Тарифы');
            $this->table(
                ['Тариф', 'Цена', 'Активен'],
                array_map(static fn (array $t): array => [
                    $t['title'],
                    $t['price'] !== null ? number_format($t['price'], 0, '.', ' ').' ₽' : '—',
                    $t['active'] ? 'да' : 'нет',
                ], $fact['tariffs']),
            );
        }

        $this->newLine();
        $this->line('## Что написать (120–200 слов, только по фактам выше)');
        $this->line('1. Что это за курс и для кого он.');
        $this->line('2. Что делают на занятиях — по блокам и датам выше.');
        $this->line('3. Что остаётся у ученика после курса.');
        $this->line('4. Кому курс не подходит.');
        $this->line('Чего нет в таблицах — не выдумывать; писать «нет данных» или опускать.');
    }
}
