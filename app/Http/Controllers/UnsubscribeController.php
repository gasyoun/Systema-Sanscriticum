<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\Lead;
use App\Models\User;
use App\Services\Consent\ConsentRecorder;
use App\Support\Unsubscribe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Отписка от рекламной рассылки по ссылке из письма (152-ФЗ, 38-ФЗ ст. 18).
 *
 * GET — страница с кнопкой подтверждения (почтовые сканеры ходят по ссылкам
 * GET-ом — отписывать на GET нельзя). POST — отписка: и с кнопки страницы, и
 * One-Click из почтового клиента (RFC 8058). Маршрут подписан (middleware
 * signed) и исключён из CSRF — почтовый клиент токена не пришлёт.
 *
 * Транзакционные письма (оплата, доступ, пароль) не затрагиваются: адрес НЕ
 * попадает в suppressed_emails, сбрасываются только флаги рекламной рассылки.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request): View
    {
        $email = $this->email($request);

        return view('unsubscribe.show', ['email' => $email, 'done' => false]);
    }

    public function store(Request $request, ConsentRecorder $consents): View|Response
    {
        $email = $this->email($request);

        $users = User::query()->whereRaw('LOWER(email) = ?', [$email])->get();
        foreach ($users as $user) {
            $user->forceFill([
                'wants_email_announcements' => false,
                'newsletter_subscribed_at' => null,
            ])->save();
            $consents->withdrawn(Consent::TYPE_PROMO, 'unsubscribe:email', $request, $user, $email);
        }

        Lead::query()->whereRaw('LOWER(email) = ?', [$email])->update(['is_promo_agreed' => false]);

        if ($users->isEmpty()) {
            $consents->withdrawn(Consent::TYPE_PROMO, 'unsubscribe:email', $request, null, $email);
        }

        // One-Click из почтового клиента ждёт просто 2xx.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 200);
        }

        return view('unsubscribe.show', ['email' => $email, 'done' => true]);
    }

    private function email(Request $request): string
    {
        $email = Unsubscribe::decode((string) $request->query('e', ''));
        abort_if($email === null, 404);

        return $email;
    }
}
