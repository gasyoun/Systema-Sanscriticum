<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\KanvaTiming;
use App\Services\Schedule\TextbookScale;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H4457 (MG 09-09): приём канонического JSON таймкодов (из Uprava
 * tools/kanva_tg_drive_ingest.py — n8n execution-история) в kanva_timings.
 * Привязка к живой грамматике по семейству курса; дедуп по video_url
 * либо по первым трём меткам (AI-вариант без url).
 */
class KanvaIngestTimingsCommand extends Command
{
    protected $signature = 'kanva:ingest-timings {--file= : канонический JSON} {--course= : id/slug курса (обязателен, если живых грамматик >1)} {--dry-run : показать план без записи}';

    protected $description = 'Ингестия таймкодов занятий в kanva_timings (H4457)';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Укажите --file=<канонический JSON> (выход tools/kanva_tg_drive_ingest.py).');

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (! is_array($payload) || ! isset($payload['sessions'])) {
            $this->error('JSON не содержит sessions.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $courseOption = (string) $this->option('course');
        $ingested = 0;
        $skipped = 0;

        foreach ($payload['sessions'] as $session) {
            $items = $session['items'] ?? [];
            if (count($items) < 2) {
                $skipped++;

                continue;
            }

            $course = $this->resolveCourse($session, $courseOption);
            if ($course === null) {
                $skipped++;
                $this->line('  пропущено (курс не определён — укажите --course=): '.($session['source'] ?? '?'));

                continue;
            }

            $group = $course->groups->first();
            $key = $session['video_url'] ?? 'labels:'.md5(implode('|', array_slice(array_map(fn ($i) => (string) ($i['label'] ?? ''), $items), 0, 3)));
            $exists = KanvaTiming::where('course_id', $course->id)
                ->where(fn ($q) => $q->where('video_url', $session['video_url'] ?? '')
                    ->orWhere('video_url', $key))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            $this->line(($dryRun ? '[dry-run] ' : '').'→ '.$course->title.': '.count($items).' таймкодов ('.($session['source'] ?? '?').')');
            if ($dryRun) {
                $ingested++;

                continue;
            }

            KanvaTiming::create([
                'course_id' => $course->id,
                'group_id' => $group?->id,
                'video_url' => $session['video_url'],
                'duration_seconds' => null,
                'timings' => $items,
                'source' => (string) ($session['source'] ?? 'n8n'),
                'valid_status' => KanvaTiming::validateTimings($items),
                'last_ingested_at' => Carbon::now(),
            ]);
            $ingested++;
        }

        $this->info(($dryRun ? 'dry-run: ' : '')."принято {$ingested}, пропущено {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Живая грамматика-канва: --course=id/slug; авто — только когда живая
     * грамматика-канва ровно одна (иначе неоднозначно, H4457).
     */
    private function resolveCourse(array $session, string $courseOption): ?Course
    {
        if ($courseOption !== '') {
            return Course::query()
                ->where('id', $courseOption)
                ->orWhere('slug', $courseOption)
                ->first();
        }

        $grammar = Course::query()
            ->where('is_active', true)->where('is_visible', true)
            ->whereHas('groups')
            ->get()
            ->filter(fn (Course $c): bool => TextbookScale::courseFamilyPublic((string) $c->title) !== null);

        return $grammar->count() === 1 ? $grammar->first() : null;
    }
}
