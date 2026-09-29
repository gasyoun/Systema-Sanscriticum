<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H4001 — один эмбеддинг bge-m3 на чанк корпуса (float32 LE BLOB, 1024 dims).
 *
 * Вектора кладутся и читаются только через {@see KnowledgeVectors} — packed
 * float32 не должен никем парситься мимо одного места.
 *
 * Этап 4: в таблице живут две полосы, различает их source_type.
 *  - 'faq'    — чанк файла корпуса; text пуст, текст читается из faq.md;
 *  - 'lesson' — фрагмент расшифровки урока; заполнены lesson_id/course_id/
 *               start_seconds/text, выдача фильтруется доступом студента.
 *
 * @property int $id
 * @property string $faq_chunk_id
 * @property string $source_type
 * @property int|null $lesson_id
 * @property string|null $course_id
 * @property int|null $start_seconds
 * @property string $model
 * @property int $dims
 * @property string $embedding
 * @property string $content_hash
 * @property string|null $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KnowledgeChunk extends Model
{
    public const SOURCE_FAQ = 'faq';

    public const SOURCE_LESSON = 'lesson';

    protected $fillable = [
        'faq_chunk_id',
        'source_type',
        'lesson_id',
        'course_id',
        'start_seconds',
        'model',
        'dims',
        'embedding',
        'content_hash',
        'text',
    ];

    protected $casts = [
        'lesson_id' => 'integer',
        'start_seconds' => 'integer',
        'dims' => 'integer',
    ];
}
