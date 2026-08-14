<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\Membership\ClubMembershipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Самостоятельный отказ от продления клуба и возврат продления (H2644),
 * плюс публичный лендинг + прайсинг клуба (H2645).
 *
 * Что здесь НЕ происходит и почему. Отказ НЕ снимает доступ: продление ручное,
 * оплаченный период уже оплачен, и отбирать его за «не продлевать» — это отъём
 * купленного. Гасится ровно намерение продлевать; право доживает до ends_at и
 * снимается демоном `membership:expire-club` на общих основаниях.
 *
 * Формулировка на странице ограничена §2.4 спецификации: 1 703 из 1 705 уроков —
 * unlisted-ссылки YouTube, и открытая студентом ссылка продолжает работать после
 * конца подписки. Поэтому текст говорит, что заканчивается ПРАВО, а не доступ.
 * Обещать отзыв того, что механизм доставки отозвать не может, — это ровно та
 * форма заявления, из которой вырастает спор о возврате денег.
 */
final class MembershipController extends Controller
{
    public function __construct(private readonly ClubMembershipService $service) {}

    /**
     * Публичный лендинг клуба (H2645). Живёт только при включённом
     * features.club_membership: страница не может появиться раньше, чем
     * оплата реально создаёт членство, — иначе CTA продаёт то, чего система
     * не выдаст. Цены берутся ТОЛЬКО из тарифов курса `club` (Filament) —
     * прайсинг и чекаут читают одну и ту же строку БД.
     */
    public function landing(): View
    {
        abort_unless((bool) config('features.club_membership'), 404);

        $course = Course::query()
            ->where('slug', (string) config('membership.club.course_slug'))
            ->first();
        abort_if($course === null, 404);

        $tariffs = $course->tariffs()
            ->where('is_active', true)
            ->whereNotNull('membership_months')
            ->orderBy('membership_months')
            ->get();
        abort_if($tariffs->isEmpty(), 404);

        return view('shop.club', [
            'course' => $course,
            'tariffs' => $tariffs,
            'monthly' => $tariffs->firstWhere('membership_months', 1) ?? $tariffs->first(),
            'shelfCount' => Course::query()->where('club_included', true)->count(),
            'freeLessonsCount' => Lesson::query()->shownOnMain()->count(),
            'freeTierOn' => (bool) config('features.membership_free_tier'),
            'cancellationOn' => (bool) config('features.membership_cancellation'),
            'grantDays' => (int) config('membership.free_tier.grant_days', 30),
        ]);
    }

    public function cancel(): RedirectResponse
    {
        abort_unless((bool) config('features.membership_cancellation'), 404);

        $membership = $this->service->cancelRenewal(auth()->user());

        if (! $membership instanceof ClubMembership) {
            return back()->with('error', 'Действующей клубной подписки нет.');
        }

        return back()->with('success', 'Продление отключено. Клуб работает до '
            .$membership->ends_at->translatedFormat('d F Y').'.');
    }

    public function resume(): RedirectResponse
    {
        abort_unless((bool) config('features.membership_cancellation'), 404);

        $membership = $this->service->resumeRenewal(auth()->user());

        if (! $membership instanceof ClubMembership) {
            return back()->with('error', 'Действующей клубной подписки нет.');
        }

        return back()->with('success', 'Продление включено обратно.');
    }
}
