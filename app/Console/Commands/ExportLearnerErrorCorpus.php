<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HomeworkComment;
use App\Services\LearnerErrorCorpus\LearnerErrorCorpusExtractor;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Console\Command;
use Throwable;

/**
 * H6252 — export the first real learner-error corpus (anonymized, 152-FZ).
 *
 * Sources: masked homework submissions/reviews from the DB and an optional
 * local stenogrammy slice. Output is a frequency-ranked CSV whose only
 * columns are anon_id, source, lemma, form, error_type, context_hash —
 * no bodies, no names, no paths. Never writes into the corpus.
 *
 * _Created: 25-11-2026 · Last updated: 25-11-2026_
 * _Dr. Mārcis Gasūns_
 */
class ExportLearnerErrorCorpus extends Command
{
    protected $signature = 'learner-errors:export
        {--output= : CSV target path}
        {--input= : JSONL of pre-masked records {anon_id,source,body}}
        {--harvest-db : harvest homework comments from DB and mask them}
        {--stenogrammy-dir= : local 152-FZ transcript corpus (read-only)}
        {--steno-limit=0 : max transcript files (0 = all)}
        {--min-rows=500 : fail-closed floor}
        {--canary : run the 20-example PII-mask control}';

    protected $description = 'H6252: export anonymized learner-error ranking CSV (152-FZ, no PII in output).';

    public function handle(): int
    {
        if ($this->option('canary')) {
            return $this->canary();
        }

        $masker = new SupportStudentPiiMasker;
        $records = [];

        if ($this->option('input')) {
            $records = array_merge($records, $this->readInput((string) $this->option('input')));
        }

        if ($this->option('harvest-db')) {
            $records = array_merge($records, $this->harvestDb($masker));
        }

        if ($this->option('stenogrammy-dir')) {
            $records = array_merge($records, $this->harvestStenogrammy(
                $masker,
                (string) $this->option('stenogrammy-dir'),
                (int) $this->option('steno-limit')
            ));
        }

        if ($records === []) {
            $this->error('No source records: pass --input, --harvest-db or --stenogrammy-dir.');

            return self::FAILURE;
        }

        $rows = (new LearnerErrorCorpusExtractor)->extract($records);
        $csv = (new LearnerErrorCorpusExtractor)->toCsv($rows);
        $output = (string) ($this->option('output') ?: 'storage/app/learner_errors_2026-10.csv');
        $dir = dirname($output);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($output, $csv) === false) {
            $this->error("Could not write {$output}");

            return self::FAILURE;
        }

        $min = (int) $this->option('min-rows');
        $this->info(sprintf('rows=%d sources=%d output=%s min=%d', count($rows), count($records), $output, $min));
        if (count($rows) < $min) {
            $this->error("Fail-closed: fewer than {$min} occurrences.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{anon_id: string, source: string, body: string}> */
    private function harvestDb(SupportStudentPiiMasker $masker): array
    {
        $records = [];
        foreach (HomeworkComment::query()->whereNotNull('body')->orderBy('id')->cursor() as $comment) {
            try {
                [$masked] = $masker->maskOrFail((string) $comment->body);
            } catch (Throwable) {
                continue; // fail-closed: drop the whole text when masking is uncertain
            }
            $records[] = [
                'anon_id' => 'u_'.substr(hash('sha256', 'h6252|'.(string) $comment->author_id), 0, 12),
                'source' => 'homework-'.($comment->author_role === 'teacher' ? 'review' : 'submission'),
                'body' => $masked,
            ];
        }

        return $records;
    }

    /** @return array<int, array{anon_id: string, source: string, body: string}> */
    private function readInput(string $path): array
    {
        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['body'])) {
                $records[] = [
                    'anon_id' => (string) ($decoded['anon_id'] ?? 'anon'),
                    'source' => (string) ($decoded['source'] ?? 'unknown'),
                    'body' => (string) $decoded['body'],
                ];
            }
        }

        return $records;
    }

    /**
     * Local 152-FZ slice: only lines with an explicit error cue enter the
     * harvest; raw bodies never leave this method.
     *
     * @return array<int, array{anon_id: string, source: string, body: string}>
     */
    private function harvestStenogrammy(SupportStudentPiiMasker $masker, string $root, int $limit): array
    {
        $records = [];
        $cue = '/ошибк|неправильн|обрати(те)? внимание|исправ|неточн|ошибоч/ui';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($limit > 0 && $n >= $limit) {
                break;
            }
            $raw = @file_get_contents($file->getPathname());
            if ($raw === false) {
                continue;
            }
            $lines = [];
            foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
                if (preg_match($cue, $line) === 1) {
                    $lines[] = $line;
                }
            }
            if ($lines === []) {
                continue;
            }
            try {
                [$masked] = $masker->maskOrFail(mb_substr(implode(' ', $lines), 0, 6000, 'UTF-8'));
            } catch (Throwable) {
                continue;
            }
            $n++;
            $records[] = [
                'anon_id' => 's_'.substr(hash('sha256', 'h6252|'.$file->getFilename()), 0, 12),
                'source' => 'stenogrammy',
                'body' => $masked,
            ];
        }

        return $records;
    }

    /** Control run: 20 known PII examples must all be masked (20/20). */
    private function canary(): int
    {
        $examples = [
            'Мария Ковалёва', 'Иван Петрович Сидоров', 'Анна Смирнова',
            'иван@example.com', 'student.mail@yandex.ru', 'a.b+tag@school.ru',
            '+7 (916) 123-45-67', '8-916-123-45-67', '+79161234567',
            'Меня зовут Екатерина', 'Меня зовут Дмитрий', '@gasyoun_student',
            'Оплатите заказ № 482913', 'счёт № 77120', '+7 999 123-45 67',
            'Ольга Петрова', 'Ольга Алексеевна', '@user_name_1990',
            'сергей电视台@mail.ru', 'Наталия Гаврилова',
        ];
        $masker = new SupportStudentPiiMasker;
        $masked = 0;
        foreach ($examples as $ex) {
            try {
                [$out, $n] = $masker->maskOrFail($ex);
            } catch (Throwable) {
                continue;
            }
            if ($n > 0 || str_contains($out, '<PII:')) {
                $masked++;
            }
        }
        $this->info(sprintf('canary=%d/20', $masked));

        return $masked === 20 ? self::SUCCESS : self::FAILURE;
    }
}
