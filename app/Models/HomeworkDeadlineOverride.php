<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkDeadlineOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'user_id',
        'group_id',
        'opens_at',
        'due_at',
        'locks_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'due_at' => 'datetime',
        'locks_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
