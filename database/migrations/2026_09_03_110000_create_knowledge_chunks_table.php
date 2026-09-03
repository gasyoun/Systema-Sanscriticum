<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4001 (Wave 3 leverage-плана) — dense-нога FAQ-ретривала.
 *
 * Одна строка на FaqChunk: embedding — сырой float32 little-endian BLOB
 * (bge-m3 = 1024 dims = 4096 байт; весь корпус ~22 МБ, поэтому MySQL, а не
 * внешний vector store — вердикт архитектуры «build the table, not a
 * service»). content_hash охраняет ре-эмбеддинг: строка протухла, когда
 * хэш чанка перестал совпадать. Ни одной колонки в существующих
 * support-таблицах не добавляется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('faq_chunk_id')->unique();
            $table->string('model')->default('bge-m3');
            $table->unsignedInteger('dims');
            $table->binary('embedding');
            $table->string('content_hash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
