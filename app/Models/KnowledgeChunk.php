<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H3234 stage 3 — one indexed corpus chunk + its optional embedding.
 * `embedding` is a packed float32 BLOB (pack('g*', ...)), never native VECTOR.
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'source',
        'chunk_id',
        'title',
        'heading_path',
        'body',
        'content_hash',
        'embedding',
        'embedding_model',
        'embedded_at',
    ];

    protected $casts = [
        'heading_path' => 'array',
        'embedded_at' => 'datetime',
    ];

    /**
     * @param  list<float>  $vector
     */
    public static function packEmbedding(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>|null
     */
    public function embeddingVector(): ?array
    {
        $blob = $this->getRawOriginal('embedding');
        if ($blob === null || $blob === '') {
            return null;
        }

        $unpacked = unpack('g*', (string) $blob);
        if ($unpacked === false) {
            return null;
        }

        return array_values($unpacked);
    }
}
