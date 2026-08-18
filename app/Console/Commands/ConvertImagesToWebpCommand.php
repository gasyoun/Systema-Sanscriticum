<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\ModelImageWebpConverter;
use App\Services\Media\WebpTranscoder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Второй рубеж автоперевода картинок в WebP (H3082) — и он же разовая миграция.
 *
 * Наблюдатель ловит обложку в момент загрузки, но только если запись шла через
 * Eloquent. Эта команда идёт по БД и подбирает всё остальное: залитое до
 * появления наблюдателя, засеянное фикстурами, вписанное прямым SQL. В
 * расписании стоит ежедневно, так что новый путь загрузки максимум сутки будет
 * отдавать тяжёлый PNG, а дальше починится сам, без человека.
 *
 * Идемпотентна: после полного прогона следующий не находит работы.
 */
class ConvertImagesToWebpCommand extends Command
{
    protected $signature = 'media:covers-to-webp
        {--dry-run : только показать, что было бы сделано}
        {--limit=0 : обработать не больше N картинок за прогон (0 — все)}';

    protected $description = 'Перевести обложки курсов в WebP (разовая миграция + ежедневная уборка)';

    public function handle(WebpTranscoder $transcoder, ModelImageWebpConverter $converter): int
    {
        if (! (bool) config('media.webp.enabled', true)) {
            $this->warn('media.webp.enabled = false — нечего делать.');

            return self::SUCCESS;
        }

        if (! $transcoder->supported()) {
            $this->error('GD без поддержки WebP — перекодировать нечем.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $converted = 0;
        $skipped = 0;
        $saved = 0;
        $reasons = [];

        foreach ((array) config('media.webp.sweep', []) as $class => $column) {
            if (! is_string($class) || ! is_a($class, Model::class, true)) {
                $this->warn("media.webp.sweep: {$class} — не модель, пропущено.");

                continue;
            }

            /** @var class-string<Model> $class */
            $query = $class::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->where($column, 'NOT LIKE', '%.webp');

            $total = (clone $query)->count();
            $this->line(sprintf('%s.%s: кандидатов %d', class_basename($class), $column, $total));

            foreach ($query->cursor() as $model) {
                if ($limit > 0 && ($converted + $skipped) >= $limit) {
                    break 2;
                }

                if ($dryRun) {
                    $this->line('  [dry-run] '.$model->getAttribute($column));
                    $skipped++;

                    continue;
                }

                $result = $converter->convert($model, $column);

                if ($result->converted) {
                    $converted++;
                    $saved += $result->saved();
                    $this->line(sprintf(
                        '  ✓ %s -> %s (%s -> %s)',
                        $result->source,
                        $result->target,
                        $this->human($result->bytesBefore),
                        $this->human($result->bytesAfter),
                    ));
                } else {
                    $skipped++;
                    $reasons[$result->reason] = ($reasons[$result->reason] ?? 0) + 1;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово: переведено %d, пропущено %d, освобождено %s.',
            $converted,
            $skipped,
            $this->human($saved),
        ));

        // Причины отказа печатаем всегда: молчаливый пропуск — это ровно тот
        // случай, когда «уборка отработала» и ничего при этом не сделала.
        foreach ($reasons as $reason => $count) {
            $this->line("  {$reason}: {$count}");
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.2f МБ', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.1f КБ', $bytes / 1024);
        }

        return $bytes.' Б';
    }
}
