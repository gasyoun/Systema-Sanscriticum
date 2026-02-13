<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $fillable = ['group_id', 'course_id', 'title', 'lesson_date', 'video_url', 'rutube_url', 'topic', 'flash_cards'];

    protected $casts = [
        'flash_cards' => 'array',
        'lesson_date' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
    public function course()
{
    return $this->belongsTo(Course::class);
}
}
