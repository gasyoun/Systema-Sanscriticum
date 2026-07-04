<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Отзыв из общей библиотеки. Привязывается к курсам через пивот
 * course_testimonial (с порядком показа sort_order).
 */
class Testimonial extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_name',
        'city',
        'avatar_path',
        'body',
        'rating',
        'media_url',
        'is_visible',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_testimonial')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
