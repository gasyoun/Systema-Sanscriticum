<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrammarExercise extends Model
{
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVAL_REQUIRED = 'approval_required';

    public const STATUS_KILLED = 'killed';

    protected $fillable = [
        'exercise_id',
        'topic_id',
        'risk_class',
        'status',
        'payload',
        'content_hash',
        'version_n',
        'previous_content_hash',
        'approval_record',
        'in_review_sample',
        'killed_at',
        'validation_errors',
    ];

    protected $casts = [
        'payload' => 'array',
        'validation_errors' => 'array',
        'in_review_sample' => 'boolean',
        'killed_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(GrammarExerciseVersion::class);
    }

    public function isLearnerVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->killed_at === null;
    }
}
