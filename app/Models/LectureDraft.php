<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LectureDraft extends Model
{
    use HasFactory;

    public const STATUS_DRAFT         = 'draft';
    public const STATUS_PREPROCESSING = 'preprocessing';
    public const STATUS_EDITING       = 'editing';
    public const STATUS_BUILT         = 'built';
    public const STATUS_PUBLISHED     = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PREPROCESSING,
        self::STATUS_EDITING,
        self::STATUS_BUILT,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'slug',
        'title',
        'status',
        'course_id',
        'lesson_id',
        'created_by',
        'data_json_path',
        'output_html_path',
        'slides_dir',
        'meta',
        'error_log',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $draft) {
            if (empty($draft->slug)) {
                $draft->slug = self::generateUniqueSlug($draft->title ?? 'lecture');
            }
        });
    }

    public static function generateUniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'lecture';
        $candidate = $slug;
        $i = 2;
        while (self::where('slug', $candidate)->exists()) {
            $candidate = $slug . '-' . $i++;
        }
        return $candidate;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workingDir(): string
    {
        return 'lectures/' . $this->id;
    }
}
