<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Tariff;

/**
 * Якоря «лесенки продуктов» (H323/H387) — единственная точка правды для всех
 * страниц, включающих shop.partials.ladder («С чего начать», конец статьи,
 * хаб «Материалы»). Цены «от N ₽» честно считаются из активных тарифов
 * видимых курсов; отсутствующая цена = null (партиал её просто не показывает).
 * Никогда не хардкодить эти числа в шаблонах/контроллерах.
 */
class ProductLadderAnchors
{
    /**
     * @return array{freeUrl: string, minRecordedPrice: mixed, minBlockPrice: mixed, catalogUrl: string, freePreviewCourse: ?Course}
     */
    public static function resolve(): array
    {
        $catalogUrl = route('shop.index');

        $minBlockPrice = Tariff::query()
            ->where('is_active', true)
            ->where('type', 'block')
            ->whereHas('course', fn ($q) => $q->where('is_visible', true))
            ->min('price');

        $minRecordedPrice = Tariff::query()
            ->where('is_active', true)
            ->whereHas('course', fn ($q) => $q->where('is_visible', true)->where('format', 'recorded'))
            ->min('price');

        // Бесплатная ступень: любой видимый курс с публичным preview-уроком.
        $freePreviewCourse = Course::query()
            ->where('is_visible', true)
            ->whereHas('previewLesson')
            ->orderBy('id')
            ->first();

        $freeUrl = $freePreviewCourse
            ? route('shop.course.preview', $freePreviewCourse->slug)
            : $catalogUrl;

        return compact('freeUrl', 'minRecordedPrice', 'minBlockPrice', 'catalogUrl', 'freePreviewCourse');
    }
}
