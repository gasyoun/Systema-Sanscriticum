<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Awcodes\Curator\Models\Media;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'slug',
        'title',
        'subtitle',
        'excerpt',
        'cover_path',
        'body',
        'reading_time',
        'author_name',
        'is_published',
        'published_at',
        'meta_title',
        'meta_description',
        'views_count',
        'yandex_metrika_id',
        'vk_pixel_id',
    ];

    protected $casts = [
        'is_published'  => 'boolean',
        'published_at'  => 'datetime',
        'reading_time'  => 'integer',
        'views_count'   => 'integer',
    ];

    // ==========================================================
    // ХУКИ МОДЕЛИ
    // ==========================================================

    protected static function booted(): void
    {
        // Авто-slug из title, если не задан
        static::saving(function (self $article): void {
            if (empty($article->slug) && !empty($article->title)) {
                $article->slug = Str::slug($article->title);
            }

            // Если включили публикацию и дата не задана — ставим текущую
            if ($article->is_published && empty($article->published_at)) {
                $article->published_at = now();
            }
        });
    }

    // ==========================================================
    // СВЯЗИ
    // ==========================================================

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(ArticleView::class);
    }
    
    /**
 * Картинки, выбранные из медиатеки для вставки в тело статьи.
 * Pivot-таблица article_inline_images хранит порядок.
 */
public function inlineImages(): BelongsToMany
{
    return $this->belongsToMany(Media::class, 'article_inline_images', 'article_id', 'media_id')
                ->withPivot('sort_order')
                ->withTimestamps()
                ->orderByPivot('sort_order');
}

    // ==========================================================
    // SCOPES (переиспользуемые условия выборки)
    // ==========================================================

    /**
     * Article::published()->get() — только опубликованные и с наступившей датой.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    /**
     * Простой полнотекстовый поиск по title + excerpt.
     * Article::search('санскрит')->get()
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('title', 'like', $term)
              ->orWhere('subtitle', 'like', $term)
              ->orWhere('excerpt', 'like', $term);
        });
    }

    // ==========================================================
    // АКСЕССОРЫ И УТИЛИТЫ
    // ==========================================================

    /**
     * Полный URL обложки для <img src>.
     * В блейде: {{ $article->cover_url }}
     */
    public function getCoverUrlAttribute(): ?string
    {
        if (empty($this->cover_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_path);
    }

    /**
     * Уникальные посетители (по visitor_hash).
     * Ленивое вычисление — вызывается только когда нужно.
     */
    public function getUniqueViewsCountAttribute(): int
    {
        return (int) $this->views()
                          ->distinct('visitor_hash')
                          ->count('visitor_hash');
    }

    /**
     * Route model binding по slug.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}