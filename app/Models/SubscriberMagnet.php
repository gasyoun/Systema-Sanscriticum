<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Лид-магнит на «полке подписчика» (H324). Свободный файл или ссылка, видимые
 * подписчику рассылки в кабинете и уходящие в письме. НЕ связан с платным
 * доступом (уроки/группы/тарифы) — чистый маркетинговый бонус.
 */
class SubscriberMagnet extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'file_path',
        'url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Активные магниты в порядке показа. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Публичная ссылка на магнит: внешний url, либо public-URL загруженного файла.
     * null — магнит настроен некорректно (ни файла, ни ссылки) и рендерить нечего.
     */
    public function publicUrl(): ?string
    {
        if (filled($this->url)) {
            return $this->url;
        }

        if (filled($this->file_path)) {
            return Storage::disk('public')->url($this->file_path);
        }

        return null;
    }
}
