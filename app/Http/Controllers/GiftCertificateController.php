<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftCertificate;
use App\Models\User;
use App\Services\GiftCertificateService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Подарочные сертификаты (H3334): активация кода получателем, публичная
 * верификация и скачивание PDF. Все поверхности гейтятся флагом
 * features.gift_certificates (404 при OFF) и стоят ДО catch-all /{slug}.
 */
class GiftCertificateController extends Controller
{
    /**
     * Форма активации. Только для залогиненных: доступ открывается конкретному
     * пользователю, гостя сначала отправляем войти/зарегистрироваться
     * (intended вернёт его сюда же после входа).
     */
    public function showActivate(Request $request)
    {
        $this->abortUnlessEnabled();

        if (! Auth::check()) {
            session()->put('url.intended', route('gift.activate'));

            return redirect()->route('login')
                ->with('success', 'Войдите или создайте аккаунт — и введите код из подарочного сертификата.');
        }

        return view('gift.activate');
    }

    public function activate(Request $request, GiftCertificateService $service)
    {
        $this->abortUnlessEnabled();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        try {
            $certificate = $service->redeem($validated['code'], $user);
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        return redirect()->route('student.dashboard')
            ->with('success', 'Сертификат «'.$certificate->grantsLabel().'» активирован — добро пожаловать!');
    }

    /**
     * Публичная верификация по номеру (QR на PDF ведёт сюда). Источник правды —
     * строка в БД; показываем только статус и ЧТО за сертификат, без персональных
     * данных получателя/покупателя.
     */
    public function verify(string $number)
    {
        $this->abortUnlessEnabled();

        $certificate = GiftCertificate::query()
            ->with(['course'])
            ->where('number', $number)
            ->first();

        return view('gift.verify', compact('certificate', 'number'));
    }

    /**
     * PDF-артефакт: покупатель и активировавший получатель (после активации —
     * как память). Прочим 403; сырой код на повторно сгенерированном PDF не
     * печатается — код существует только в письме покупателя.
     */
    public function download(Request $request, GiftCertificate $certificate, GiftCertificateService $service)
    {
        $this->abortUnlessEnabled();

        $userId = Auth::id();
        $isBuyer = $certificate->payment?->user_id === $userId;
        $isRecipient = $certificate->activated_by_user_id !== null && $certificate->activated_by_user_id === $userId;

        if (! $isBuyer && ! $isRecipient) {
            abort(403);
        }

        // PDF печатается БЕЗ кода (код одноразовый и живёт в письме покупателя):
        // повторная генерация не восстанавливает секрет — сертификат верифицируется
        // номером через публичную страницу.
        $pdf = $service->renderPdf($certificate);

        return $pdf->download('gift-certificate-'.$certificate->number.'.pdf');
    }

    private function abortUnlessEnabled(): void
    {
        if (! config('features.gift_certificates')) {
            abort(404);
        }
    }
}
