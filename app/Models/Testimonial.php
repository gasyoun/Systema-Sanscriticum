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
        'is_featured',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_visible' => 'boolean',
        'is_featured' => 'boolean',
    ];

    /** Отзывы для общесайтовой полосы на витрине каталога (H323). */
    public function scopeFeatured($query)
    {
        return $query->where('is_visible', true)->where('is_featured', true);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_testimonial')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
