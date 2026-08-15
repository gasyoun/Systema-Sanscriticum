<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Support\CallbackRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Личный кабинет: запрос обратного звонка (H2747, Phase 0/1 of the H2486
 * telephony packet). Работает только при features.telephony_callback_request
 * = true — иначе 404. Пишет FollowUpTask + CallEvent::requested; никакого
 * провайдера, никакого PSTN.
 *
 * @see docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md §4.3
 */
class CallbackRequestController extends Controller
{
    public function show(): View
    {
        abort_unless((bool) config('features.telephony_callback_request'), 404);

        return view('student.support-callback');
    }

    public function store(Request $request, CallbackRequestService $callbacks): RedirectResponse
    {
        abort_unless((bool) config('features.telephony_callback_request'), 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
            'consent' => ['required', 'accepted'],
        ]);

        $callbacks->requestCallback($user, $data['phone'], $data['note'] ?? null, $request);

        return back()->with(
            'callback_request_status',
            'Заявка принята — куратор перезвонит вам в рабочее время.',
        );
    }
}
