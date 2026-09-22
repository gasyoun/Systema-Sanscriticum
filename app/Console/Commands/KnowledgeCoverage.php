<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * H5065 — «вся ли инфа из фака» реально доехала до базы знаний.
 *
 * Зачем отдельная команда. `knowledge:index` считает дельту по content_hash и
 * молчит, когда корпус распарсился в 80 чанков вместо 82: для него «меньше
 * чанков» — это просто меньше работы. Пропажа раздела выглядит как успешный
 * прогон, а студент получает «данных нет» на вопрос, ответ на который в FAQ
 * есть. Тот же класс тихой лжи, что H3812 в деньгах: восемь зеленых тестов
 * проверяли половину контракта.
 *
 * Поэтому здесь ДВА независимых счета, и они обязаны сойтись:
 *  1. сканер файла — сколько разделов с непустым телом в самом faq.md;
 *  2. парсер — сколько чанков из него вышло.
 * Сканер намеренно НЕ переиспользует парсер: сканер, написанный тем же кодом,
 * что и разбираемое, доказывает только то, что код согласен сам с собой.
 *
 * Что именно считается потерей (и валит команду):
 *  - раздел с непустым телом, не давший чанка;
 *  - ДУБЛЬ заголовочного пути: два раздела с одинаковым chunk_id. Это тихая
 *    потеря тела, а не косметика: `faq_chunk_id` в knowledge_chunks уникален,
 *    поэтому второе тело затирает первое и в индексе остается одно.
 *
 * Что потерей НЕ считается и почему: текст ДО первого заголовка (в faq.md это
 * служебная шапка про генерацию из ors_faq/wiki и оговорка, что цены сюда не
 * дублируются). В retrieval он не идет намеренно — это не ответ студенту, а
 * инструкция для того, кто правит корпус, и в промпте студентской модели ей
 * не место. Но и молчать о ней нельзя: команда печатает ее объем отдельной
 * колонкой, поэтому «шапка выросла до полстраницы» видно, а не спрятано.
 *
 * Второй ответ команды — про эмбеддинги: сколько чанков лежит в
 * knowledge_chunks, сколько протухло по хэшу и сколько не проэмбедлено вовсе.
 * Это и есть проверка «база знаний проэмбедена», а не «команда отработала».
 */
class KnowledgeCoverage extends Command
{
    protected $signature = 'knowledge:coverage
        {--path= : только этот файл корпуса (по умолчанию основной + extra_paths)}
        {--require-embedded : ненулевой код возврата, если чанк не проэмбедлен или устарел}
        {--json : машинный вывод вместо таблиц}';

    protected $description = 'H5065: prove no FAQ section is silently lost between the corpus file and knowledge_chunks';

    public function handle(FaqCorpusParser $parser): int
    {
        $files = $this->files($parser);
        if ($files === []) {
            $this->error('knowledge: no corpus files to check');

            return self::FAILURE;
        }

        $report = ['files' => [], 'lost' => [], 'duplicated' => [], 'embedded' => []];
        $lost = [];
        $duplicated = [];

        foreach ($files as $file) {
            $row = $this->inspectFile($parser, $file);
            $report['files'][] = $row;
            foreach ($row['missing'] as $missing) {
                $lost[] = $row['file'].' → '.$missing;
            }
            foreach ($row['duplicates'] as $duplicate) {
                $duplicated[] = $row['file'].' → '.$duplicate;
            }
        }

        $report['lost'] = $lost;
        $report['duplicated'] = $duplicated;
        // Проверка эмбеддингов идет по ТОМУ ЖЕ набору файлов, что и скан
        // потерь: с --path отчет «проэмбедено» обязан говорить про этот файл, а
        // не про живой корпус (иначе кастомный корпус в тестах давал бы вечное
        // «82 чанка без эмбеддинга»).
        $report['embedded'] = $embedding = $this->embeddingCoverage($this->selectedChunks($parser));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->render($report, $embedding);
        }

        if ($duplicated !== []) {
            $this->error(sprintf(
                'knowledge: %d дублирующихся chunk_id — второе тело затирает первое в knowledge_chunks (faq_chunk_id уникален)',
                count($duplicated),
            ));

            return self::FAILURE;
        }

        if ($lost !== []) {
            $this->error(sprintf(
                'knowledge: %d раздел(ов) с непустым телом не доехали до чанков — база знаний НЕПОЛНАЯ',
                count($lost),
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('require-embedded') && ($embedding['missing'] > 0 || $embedding['stale'] > 0)) {
            $this->error(sprintf(
                'knowledge: %d чанк(ов) без эмбеддинга и %d устарели — запустите knowledge:index',
                $embedding['missing'],
                $embedding['stale'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function files(FaqCorpusParser $parser): array
    {
        $explicit = $this->explicitPath();

        if ($explicit !== null) {
            return is_file($explicit) ? [$explicit] : [];
        }

        $files = [$parser->path()];
        foreach ($parser->extraPaths() as $extra) {
            $files[] = $extra;
        }

        return array_values(array_filter($files, static fn (string $f): bool => is_file($f)));
    }

    private function explicitPath(): ?string
    {
        $explicit = $this->option('path');
        if (! is_string($explicit) || $explicit === '') {
            return null;
        }

        return str_starts_with($explicit, '/') || preg_match('/^[A-Za-z]:/', $explicit) === 1
            ? $explicit
            : base_path($explicit);
    }

    /**
     * Чанки того набора, который команда сейчас проверяет: явный файл — как
     * есть, иначе — полный корпус с префиксами дополнительных файлов (ровно то,
     * что видит knowledge:index и, значит, knowledge_chunks).
     *
     * @return list<FaqChunk>
     */
    private function selectedChunks(FaqCorpusParser $parser): array
    {
        $explicit = $this->explicitPath();

        return $explicit !== null && is_file($explicit)
            ? $parser->parseFile($explicit)
            : $parser->chunks();
    }

    /**
     * @return array{file: string, sections: int, content_sections: int, chunks: int, empty_chunks: int, lost_chars: int, preface_chars: int, missing: list<string>, duplicates: list<string>}
     */
    private function inspectFile(FaqCorpusParser $parser, string $file): array
    {
        $scan = $this->scanFile($parser, $file);
        $expected = $scan['sections'];
        $chunks = $parser->parseFile($file);

        $actual = [];
        $empty = 0;
        $chunkChars = 0;
        foreach ($chunks as $chunk) {
            $actual[$chunk->chunkId] = true;
            if (trim($chunk->body) === '') {
                $empty++;
            }
            $chunkChars += mb_strlen(trim($chunk->body));
        }

        $missing = [];
        $ids = [];
        $expectedChars = 0;
        $contentSections = 0;
        foreach ($expected as $section) {
            if (trim($section['body']) === '') {
                continue;
            }
            $contentSections++;
            $expectedChars += mb_strlen(trim($section['body']));
            $ids[] = $section['id'];
            if (! isset($actual[$section['id']])) {
                $missing[] = $section['title'];
            }
        }

        // Дубль заголовочного пути: в knowledge_chunks ключ faq_chunk_id
        // уникален, поэтому два тела с одним id — это потеря одного из них.
        $counts = array_count_values($ids);
        $duplicates = [];
        foreach ($counts as $id => $count) {
            if ($count > 1) {
                $duplicates[] = $id.' ×'.$count;
            }
        }

        return [
            'file' => $file,
            'sections' => count($expected),
            'content_sections' => $contentSections,
            'chunks' => count($chunks),
            'empty_chunks' => $empty,
            'lost_chars' => max(0, $expectedChars - $chunkChars),
            'preface_chars' => $scan['preface_chars'],
            'missing' => $missing,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Независимый сканер корпуса: разделы `## `/`### ` и их тела до следующего
     * заголовка. Правила скипа — только те, что записаны в контракте формата:
     * HTML-комментарии. Ничего из логики парсера здесь нет.
     *
     * @return array{sections: list<array{id: string, title: string, path: list<string>, body: string}>, preface_chars: int}
     */
    private function scanFile(FaqCorpusParser $parser, string $file): array
    {
        $lines = preg_split("/\r\n|\n|\r/", (string) file_get_contents($file)) ?: [];

        $h2 = null;
        $h3 = null;
        $body = [];
        $out = [];
        $preface = [];
        $seenHeading = false;
        // Многострочный HTML-комментарий: контракт формата разрешает
        // комментарии-шапки в несколько строк, и построчный скип «строка
        // начинается с <!--» оставлял бы хвост комментария в «тексте до
        // заголовка» — отчет показывал бы 134 символа шапки там, где шапки нет
        // (faq_from_lectures.md — ровно такой случай).
        $inComment = false;

        $flush = function () use (&$out, &$h2, &$h3, &$body, $parser): void {
            $path = array_values(array_filter([$h2, $h3], static fn (?string $s): bool => $s !== null && $s !== ''));
            if ($path !== []) {
                $out[] = [
                    'id' => $parser->chunkIdFromPath($path),
                    'title' => $h3 ?? $h2 ?? '',
                    'path' => $path,
                    'body' => implode("\n", $body),
                ];
            }
            $body = [];
        };

        foreach ($lines as $line) {
            if ($inComment) {
                if (str_contains($line, '-->')) {
                    $inComment = false;
                }

                continue;
            }
            if (str_contains($line, '<!--')) {
                if (! str_contains($line, '-->')) {
                    $inComment = true;
                }

                continue;
            }
            if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
                $flush();
                $seenHeading = true;
                $h3 = trim($m[1]);

                continue;
            }
            if (preg_match('/^##\s+(.+)$/u', $line, $m)) {
                $flush();
                $seenHeading = true;
                $h2 = trim($m[1]);
                $h3 = null;

                continue;
            }
            if (preg_match('/^#\s+/u', $line)) {
                // H1 — титул документа, а не граница чанка (та же семантика, что
                // у парсера). Поэтому он НЕ открывает «после заголовка»: иначе
                // текст между H1 и первым `##` (в faq.md — служебная шапка)
                // молча пропадал бы из обоих счетов сразу.
                continue;
            }
            if (! $seenHeading) {
                $preface[] = $line;
            }
            $body[] = $line;
        }
        $flush();

        return [
            'sections' => $out,
            'preface_chars' => mb_strlen(trim(implode("\n", $preface))),
        ];
    }

    /**
     * @param  list<FaqChunk>  $chunks
     * @return array{available: bool, model: string, dims: int, rows: int, expected: int, embedded: int, stale: int, missing: int, error: ?string}
     */
    private function embeddingCoverage(array $chunks): array
    {
        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);

        $base = [
            'available' => false,
            'model' => $model,
            'dims' => $dims,
            'rows' => 0,
            'expected' => count($chunks),
            'embedded' => 0,
            'stale' => 0,
            'missing' => 0,
            'error' => null,
        ];

        try {
            $rows = DB::table('knowledge_chunks')
                ->where('source_type', KnowledgeChunk::SOURCE_FAQ)
                ->where('model', $model)
                ->where('dims', $dims)
                ->pluck('content_hash', 'faq_chunk_id');
        } catch (Throwable $e) {
            $base['error'] = $e->getMessage();

            return $base;
        }

        $embedded = 0;
        $stale = 0;
        $missing = 0;

        foreach ($chunks as $chunk) {
            $hash = KnowledgeVectors::contentHash($model, $dims, $chunk->searchText());
            $known = $rows[$chunk->chunkId] ?? null;

            if ($known === null) {
                $missing++;

                continue;
            }
            if ((string) $known !== $hash) {
                $stale++;

                continue;
            }
            $embedded++;
        }

        return [
            ...$base,
            'available' => true,
            'rows' => $rows->count(),
            'embedded' => $embedded,
            'stale' => $stale,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array{files: list<array<string, mixed>>, lost: list<string>, duplicated: list<string>}  $report
     * @param  array<string, mixed>  $embedding
     */
    private function render(array $report, array $embedding): void
    {
        $rows = [];
        foreach ($report['files'] as $file) {
            $rows[] = [
                'file' => basename((string) $file['file']),
                'sections' => $file['sections'],
                'with body' => $file['content_sections'],
                'chunks' => $file['chunks'],
                'empty chunks' => $file['empty_chunks'],
                'body chars' => $file['lost_chars'] === 0 ? 'all present' : '-'.$file['lost_chars'],
                'preface' => $file['preface_chars'] === 0 ? '—' : $file['preface_chars'].' chars (не индексируется)',
            ];
        }

        $this->table(
            ['file', 'sections', 'with body', 'chunks', 'empty chunks', 'body chars', 'preface'],
            $rows,
        );

        if ($embedding['available']) {
            $this->info(sprintf(
                'embeddings (%s, %d dims): %d/%d up to date, %d stale, %d not embedded yet; rows=%d',
                $embedding['model'],
                $embedding['dims'],
                $embedding['embedded'],
                $embedding['expected'],
                $embedding['stale'],
                $embedding['missing'],
                $embedding['rows'],
            ));
        } else {
            $this->warn('embeddings: база недоступна ('.$embedding['error'].')');
        }

        foreach ($report['duplicated'] as $line) {
            $this->warn('дубль chunk_id: '.$line);
        }

        foreach ($report['lost'] as $line) {
            $this->warn('потерян раздел: '.$line);
        }
    }
}
