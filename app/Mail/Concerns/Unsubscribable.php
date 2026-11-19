<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Support\Unsubscribe;
use Illuminate\Mail\Mailables\Headers;

/**
 * Рекламное письмо (152-ФЗ, 38-ФЗ ст. 18): в каждом — ссылка отписки.
 *
 *  - заголовки List-Unsubscribe + List-Unsubscribe-Post (One-Click, RFC 8058) —
 *    кнопка «Отписаться» в Gmail/Яндекс Почте/Mail.ru;
 *  - переменная $unsubscribeUrl в шаблоне — для подвала
 *    emails/partials/unsubscribe-footer.
 *
 * Ссылка строится по адресу получателя ($this->to), поэтому трейт работает
 * только с Mail::to(...)->send/queue — так отправляются все рекламные письма.
 * Транзакционные письма (оплата, доступ, пароль) трейт НЕ используют.
 */
trait Unsubscribable
{
    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();

        return new Headers(text: $url === null ? [] : [
            'List-Unsubscribe' => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function buildViewData()
    {
        return array_merge(parent::buildViewData(), [
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    public function unsubscribeUrl(): ?string
    {
        $address = $this->to[0]['address'] ?? null;

        return is_string($address) && $address !== '' ? Unsubscribe::url($address) : null;
    }
}
