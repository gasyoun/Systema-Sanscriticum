<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'comment_id',
        'disk',
        'path',
        'original_name',
        'size',
        'mime',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(HomeworkComment::class, 'comment_id');
    }

    /** Сабмишн, к которому относится файл (через комментарий). */
    public function submission(): ?HomeworkSubmission
    {
        return $this->comment?->submission;
    }

    public function humanSize(): string
    {
        return self::humanSizeFor((int) $this->size);
    }

    public static function humanSizeFor(int|float|null $bytes): string
    {
        $bytes = max(0, (int) ($bytes ?? 0));
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' МБ';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' КБ';
        }

        return $bytes.' Б';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
