<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Tariff;
use Illuminate\View\View;

/**
 * H3916: лендинг годовой подписки на архив факультативов «в записи»
 * (модель VedaTNG). Живёт только при features.recorded_subscription = true.
 *
 * Честные цифры — из БД, никаких захардкоженных цен (урок H2645):
 * анкор = сумма актуальных цен «весь курс» по архиву, N записей = размер
 * архива. Рассрочки на запуске нет (пункт миссии).
 */
class SubscriptionLandingController extends Controller
{
    public function __invoke(): View
    {
        abort_unless((bool) config('features.recorded_subscription', false), 404);

        $archive = Course::query()->subscriptionArchive()->get();

        abort_if($archive->isEmpty(), 404);

        $anchorSum = 0;
        foreach ($archive as $course) {
            $full = $course->tariffs()
                ->where('is_active', true)
                ->where('type', 'full')
                ->orderBy('price')
                ->value('price');

            $anchorSum += (float) $full;
        }

        $club = Course::query()
            ->where('slug', (string) config('membership.club.course_slug', 'club'))
            ->first();

        $tariffs = $club === null ? collect() : Tariff::query()
            ->where('course_id', $club->id)
            ->where('is_active', true)
            ->whereNotNull('membership_months')
            ->whereIn('membership_tier', [MembershipTier::Standard->value, MembershipTier::Professional->value])
            ->orderBy('membership_tier')
            ->orderBy('membership_months')
            ->get()
            ->values();

        abort_if($tariffs->isEmpty(), 404);

        return view('shop.subscription', [
            'archive' => $archive,
            'archiveCount' => $archive->count(),
            'anchorSum' => $anchorSum,
            'tariffs' => $tariffs,
            'standard' => $tariffs->where('membership_tier', MembershipTier::Standard->value),
            'professional' => $tariffs->where('membership_tier', MembershipTier::Professional->value),
        ]);
    }
}
