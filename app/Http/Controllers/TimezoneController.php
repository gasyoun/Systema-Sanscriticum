<?php

namespace App\Http\Controllers;

use App\Support\Timezone;
use Illuminate\Http\Request;

/**
 * H4434 — timezone capture (MG 09-09-2026).
 *
 * Два источника правды об устройстве/человеке:
 *  1. device-TZ из JS (Intl.DateTimeFormat().resolvedOptions().timeZone) —
 *     VPN-иммунный сигнал (VPN меняет IP, но не часы устройства).
 *  2. Ручной селектор в кабинете + форма «временно в другой стране» с датой
 *     возврата (tz_override + tz_override_until).
 *
 * Писать в профиль ученика может только авторизованный; гости получают
 * клиентскую конверсию без записи в БД (cookie, Phase 4 surface).
 */
class TimezoneController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:64'],
            'source' => ['nullable', 'in:manual,device'],
        ]);

        if (! Timezone::isValid($data['timezone'])) {
            return back()->with('tz_status', 'Неизвестная таймзона: '.$data['timezone']);
        }

        $user = $request->user();
        $user->timezone = $data['timezone'];
        $user->tz_source = $data['source'] ?? 'manual';
        // Смена постоянной зоны сбрасывает оверрайд — они конфликтуют по смыслу.
        $user->tz_override = null;
        $user->tz_override_until = null;
        $user->save();

        return back()->with('tz_status', 'Ваше время сохранено: '.$data['timezone']);
    }

    /**
     * Временное пребывание (MG 09-09-2026: «уехал на полтора месяца в Индию»).
     * Зона оверрайда действует до tz_override_until (включительно), потом ленивый
     * авто-возврат на постоянную — без крона.
     */
    public function override(Request $request)
    {
        $data = $request->validate([
            'tz_override' => ['required', 'string', 'max:64'],
            'tz_override_until' => ['required', 'date', 'after:today'],
        ]);

        if (! Timezone::isValid($data['tz_override'])) {
            return back()->with('tz_status', 'Неизвестная таймзона: '.$data['tz_override']);
        }

        $user = $request->user();
        $user->tz_override = $data['tz_override'];
        $user->tz_override_until = $data['tz_override_until'];
        $user->save();

        return back()->with(
            'tz_status',
            'Временное пребывание сохранено: '.$data['tz_override'].' до '.$data['tz_override_until']
        );
    }

    public function clearOverride(Request $request)
    {
        $user = $request->user();
        $user->tz_override = null;
        $user->tz_override_until = null;
        $user->save();

        return back()->with('tz_status', 'Временное пребывание снято — вернулись к постоянной зоне.');
    }

    /**
     * Silent device-TZ захват: JS на кабинете делает POST один раз, если зона
     * ещё не сохранена (или отличается и источник был device — тогда обновляем).
     * Без модалок; плашку-подтверждение показывает Blade-чип в кабинете.
     */
    public function deviceCapture(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        if (! Timezone::isValid($data['timezone'])) {
            return response()->json(['ok' => false], 422);
        }

        $user = $request->user();

        // Ручная зона старше device-TZ: не затираем решение человека.
        if ($user->tz_source === 'manual' && $user->timezone !== $data['timezone']) {
            return response()->json(['ok' => true, 'kept' => 'manual']);
        }

        if ($user->timezone !== $data['timezone'] || $user->tz_source !== 'device') {
            $user->timezone = $data['timezone'];
            $user->tz_source = 'device';
            $user->save();
        }

        return response()->json(['ok' => true]);
    }
}
