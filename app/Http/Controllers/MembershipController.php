<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Membership\ClubMembershipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
     * Лендинг подписки на личный кабинет — ПРЕВЬЮ ДЛЯ СУПЕР-АДМИНА.
     *
     * Голосовой запуск MG 23-11-2026: контракт страницы — месячная лестница
     * кабинета (Свободный / Базовый / Клуб / Top «позже») с ИИ-функциями в
     * тарифах; публичный запуск — после 1 января 2027 (цены факультативов
     * с января могут пересматриваться). До отдельного флипа страницу видит
     * только супер-админ, остальные получают 404 — как и до редизайна,
     * когда снятый с продажи Базовый ронял гейт H2645 для всех.
     *
     * Правило цен H2645 сохранено: число на карточке — это строка БД
     * (месячный тариф курса `club`); fallback на membership.tiers.*.monthly_price
     * допустим только когда строки нет вовсе (превью не должно 404-ить).
     * Чекаут-ссылка — только у активного тарифа; Top сознательно без ссылки:
     * он не продаётся до отдельного go/no-go (config/features.php).
     */
    public function landing(Request $request): View
    {
        abort_unless($request->user() instanceof User && $request->user()->isSuperAdmin(), 404);
        abort_unless((bool) config('features.club_membership'), 404);

        $course = Course::query()
            ->where('slug', (string) config('membership.club.course_slug'))
            ->first();
        abort_if($course === null, 404);

        $monthlyTariff = fn (MembershipTier $tier): ?Tariff => $course->tariffs()
            ->where('membership_months', 1)
            ->where('membership_tier', $tier->value)
            ->first();

        $basicTariff = $monthlyTariff(MembershipTier::Basic);
        $clubTariff = $monthlyTariff(MembershipTier::Club);

        return view('shop.club', [
            'basicTariff' => $basicTariff,
            'clubTariff' => $clubTariff,
            'basicPrice' => $basicTariff?->price ?? MembershipTier::Basic->monthlyPrice(),
            'clubPrice' => $clubTariff?->price ?? MembershipTier::Club->monthlyPrice(),
            'topPrice' => MembershipTier::Top->monthlyPrice(),
            'freeLessonsCount' => Lesson::query()->shownOnMain()->count(),
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
