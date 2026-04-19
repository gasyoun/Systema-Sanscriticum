<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ArticleCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Авто-генерация slug из name, если админ не заполнил вручную.
     * Срабатывает на creating и updating.
     */
    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if (empty($category->slug) && !empty($category->name)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Все статьи в рубрике.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    /**
     * Только опубликованные статьи в рубрике (для публичной части).
     */
    public function publishedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id')
                    ->where('is_published', true)
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
    }

    /**
     * Laravel использует это для route model binding.
     * В роутах будем писать {category:slug} — и он найдёт по slug, а не по id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}