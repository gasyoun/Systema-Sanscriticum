<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3234 stage 3 — vector store for the hybrid RAG experiment (Ollama on Ivan's
 * GPU, gate confirmed open 01-09-2026 19:00 UTC). BLOB float32, deliberately
 * NOT MySQL's native VECTOR type (issue #1633 stage 3 / H3234 acceptance:
 * "Fail =: ... native VECTOR"). ~22 МБ на 5–6 тыс. чанков — Qdrant не нужен.
 *
 * One row per corpus chunk (FAQ section today; lecture transcripts are a
 * separate flag in stage 4, not this migration). `embedding` stays NULL
 * until IndexKnowledgeChunkJob successfully reaches Ollama — HybridRetriever
 * degrades to pure BM25 while it is NULL, so this table shipping does not
 * change any live retrieval behaviour by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            // 'faq' today; 'lecture' arrives with stage 4 under its own flag.
            $table->string('source', 32);
            // Stable id within its source — for faq.md this is FaqChunk::$chunkId
            // (heading-path slug), for lectures a future transcript-derived id.
            $table->string('chunk_id');
            $table->string('title');
            $table->json('heading_path');
            $table->text('body');
            // sha256 of body — dedup on reindex (H3234 packet: "Дедуп транскриптов
            // sha256"): unchanged content skips a wasted Ollama round-trip.
            $table->char('content_hash', 64);
            // float32 vector, packed with pack('g*', ...). NULL = not embedded yet
            // (tunnel down, or indexing job has not run) → retriever falls back
            // to BM25-only, same as a NullEmbeddingProvider result.
            $table->binary('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'chunk_id']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
