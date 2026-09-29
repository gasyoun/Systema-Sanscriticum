<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * H4001 — эмбеддинг одной партии чанков и upsert в knowledge_chunks.
 *
 * Очередь `imports` (supervisor-long): та же, что у прочей тяжёлой работы —
 * новый supervisor не изобретается (вердикт implementation-слоя).
 *
 * Ретраи ЖИВУТ ВНУТРИ ДЖОБЫ (контракт H4001): tries=3 с бэккоффи —
 * осознанное отступление от «tries = 1 как у всего supervisor-long», потому
 * что обрыв туннеля — штатная ситуация, а не дефект данных. Пустая партия и
 * пустой вектор от NullEmbeddingProvider НЕ пишутся: knowledge_chunks не
 * должна наполняться мусором при выключенной dense-ноге.
 */
final class KnowledgeEmbedChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        /**
         * `text` — то, что уходит в эмбеддинг. Остальное появилось на Этапе 4
         * и описывает полосу урока; для FAQ-полосы ключи отсутствуют, и строка
         * пишется ровно как раньше.
         *
         * @var list<array{faq_chunk_id: string, model: string, dims: int, content_hash: string, text: string, source_type?: string, lesson_id?: int|null, course_id?: string|null, start_seconds?: int|null, store_text?: string|null}>
         */
        public readonly array $items,
    ) {
        $this->onQueue('imports');
    }

    public function handle(EmbeddingProvider $embeddings): void
    {
        if ($this->items === []) {
            return;
        }

        $vectors = $embeddings->embedBatch(array_map(
            static fn (array $item): string => (string) $item['text'],
            $this->items,
        ));

        foreach ($this->items as $i => $item) {
            $vector = $vectors[$i] ?? [];
            if ($vector === []) {
                Log::warning('knowledge: empty embedding, chunk not written', [
                    'faq_chunk_id' => $item['faq_chunk_id'],
                ]);

                continue;
            }

            KnowledgeChunk::updateOrCreate(
                ['faq_chunk_id' => (string) $item['faq_chunk_id']],
                [
                    'source_type' => (string) ($item['source_type'] ?? KnowledgeChunk::SOURCE_FAQ),
                    'lesson_id' => isset($item['lesson_id']) ? (int) $item['lesson_id'] : null,
                    'course_id' => isset($item['course_id']) ? (string) $item['course_id'] : null,
                    'start_seconds' => isset($item['start_seconds']) ? (int) $item['start_seconds'] : null,
                    'model' => (string) $item['model'],
                    'dims' => (int) $item['dims'],
                    'embedding' => KnowledgeVectors::pack($vector),
                    'content_hash' => (string) $item['content_hash'],
                    'text' => isset($item['store_text']) ? (string) $item['store_text'] : null,
                ],
            );
        }
    }
}
