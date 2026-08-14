<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\HindiAttachmentTextExtractor;
use App\Services\HindiPdfSidecarWriter;
use Illuminate\Console\Command;

/**
 * H2731 — bounded PDF sidecar for one lesson. Dry-run unless --apply.
 *
 * Does not flip HINDI_ATTACHMENT_DRILLS. Does not scrape Telegram.
 * Report never includes extracted textbook text — counts and script flags only.
 */
class HindiPdfSidecarCommand extends Command
{
    protected $signature = 'hindi:pdf-sidecar
        {lesson : Lesson id}
        {--apply : Write sibling .txt (default is dry-run)}
        {--force : Overwrite an existing sidecar}
        {--max-pages=8 : Page cap (first N pages)}
        {--max-bytes=15000000 : Skip if the PDF is larger}
        {--json : Print JSON}';

    protected $description = 'Bounded Hindi PDF → sibling .txt (gs / optional tesseract; no Telegram)';

    public function handle(HindiAttachmentTextExtractor $files, HindiPdfSidecarWriter $writer): int
    {
        $lesson = Lesson::query()->find((int) $this->argument('lesson'));
        if ($lesson === null) {
            $this->error('Lesson not found.');

            return self::FAILURE;
        }

        $maxPages = max(1, (int) $this->option('max-pages'));
        $maxBytes = max(1, (int) $this->option('max-bytes'));
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $rows = [];
        foreach ($files->listedPaths($lesson) as $listed) {
            $path = $listed['path'];
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }
            $rows[] = $apply
                ? $writer->apply($path, $maxPages, $maxBytes, $force)
                : $writer->plan($path, $maxPages, $maxBytes);
        }

        $report = [
            'lesson_id' => (int) $lesson->id,
            'apply' => $apply,
            'max_pages' => $maxPages,
            'max_bytes' => $maxBytes,
            'pdf_count' => count($rows),
            'files' => $rows,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('lesson_id='.$report['lesson_id'].' pdfs='.$report['pdf_count'].' apply='.($apply ? 'yes' : 'no'));
        foreach ($rows as $row) {
            $this->line($row['status'].'  '.$row['path'].'  chars='.$row['chars'].'  reason='.(string) ($row['reason'] ?? ''));
        }

        return self::SUCCESS;
    }
}
