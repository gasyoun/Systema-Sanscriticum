<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Support\Faq\KnowledgeVectorStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H4001 — один эмбеддинг bge-m3 на FaqChunk (float32 LE BLOB, 1024 dims).
 *
 * Вектора кладутся и читаются только через
 * {@see KnowledgeVectorStore} — packed float32
 * не должен никем парситься мимо одного места.
 *
 * @property int $id
 * @property string $faq_chunk_id
 * @property string $model
 * @property int $dims
 * @property string $embedding
 * @property string $content_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KnowledgeChunk extends Model
{
    protected $fillable = ['faq_chunk_id', 'model', 'dims', 'embedding', 'content_hash'];
}
