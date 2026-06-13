<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeworkComment extends Model
{
    use HasFactory;

    public const ROLE_STUDENT = 'student';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_ADMIN = 'admin';

    public const TYPE_SUBMISSION = 'submission';

    public const TYPE_REVIEW = 'review';

    public const TYPE_MESSAGE = 'message';

    protected $fillable = [
        'submission_id',
        'author_id',
        'author_role',
        'type',
        'body',
        'new_status',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(HomeworkSubmission::class, 'submission_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(HomeworkFile::class, 'comment_id');
    }
}
