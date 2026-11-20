<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H6327 — постоянная доска прописи (Excalidraw) «студент × занятие».
 *
 * Замена webwhiteboard.com: сцена лежит в нашей БД и переживает перезагрузку,
 * перезапуск браузера и смену устройства. Урока может не быть (null) — тогда
 * это личная «Общая доска» студента без привязки к занятию.
 */
class DevanagariBoard extends Model
{
    /** Жёсткий потолок размера сцены в байтах (prune-политика handoff H6327). */
    public const SCENE_MAX_BYTES = 2_000_000;

    protected $fillable = ['student_id', 'lesson_id', 'title', 'scene', 'elements_count'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
