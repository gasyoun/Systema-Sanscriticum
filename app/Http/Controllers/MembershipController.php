<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Services\Membership\ClubMembershipService;
use Illuminate\Http\RedirectResponse;

/**
 * Самостоятельный отказ от продления клуба и возврат продления (H2644).
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
