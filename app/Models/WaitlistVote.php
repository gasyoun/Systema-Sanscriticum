<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Голос зарегистрированного ученика за строку списка ожидания (1 с юзера на строку). */
class WaitlistVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_waitlist_item_id',
        'user_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CourseWaitlistItem::class, 'course_waitlist_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
