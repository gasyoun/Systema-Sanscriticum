<?php

namespace App\Http\Resources;

use App\Models\CourseWaitlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Публичный фид «Списка ожидания» (волна 1, MG 31-08-2026).
 *
 * ЭТО ГРАНИЦА БЕЗОПАСНОСТИ (allowlist). Наружу — только витринные поля по
 * слагам: никаких числовых id (item_id/course_id/user_id), никаких PII, и
 * никакого прогноза оплат — прогноз виден только в админке.
 *
 * @mixin CourseWaitlistItem
 */
class PublicWaitlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CourseWaitlistItem $item */
        $item = $this;

        return [
            'title' => $item->course_title,
            'teacher' => $item->teacher_name,
            'slot' => $item->slot,
            'kind' => $item->kind,
            // «не раньше» — дата строки; null = не ограничена.
            'earliest_start' => $item->earliest_start_at?->toDateString(),
            'price' => $item->block_price_rub,
            'votes' => (int) $item->votes_count,
            'min_payers' => $item->min_payers,
            'status' => $item->status,
            'slug' => $item->slug,
            // Привязанный курс появляется только когда он существует и виден.
            'course' => $item->course?->is_visible
                ? ['title' => $item->course->title, 'slug' => $item->course->slug]
                : null,
        ];
    }
}
