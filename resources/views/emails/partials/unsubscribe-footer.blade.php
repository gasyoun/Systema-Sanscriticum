{{-- 152-ФЗ / 38-ФЗ ст. 18: ссылка отписки в каждом рекламном письме.
     $unsubscribeUrl даёт трейт App\Mail\Concerns\Unsubscribable. --}}
@if(!empty($unsubscribeUrl))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
    <tr>
        <td style="padding: 16px 20px 24px; text-align: center; font-family: Arial, sans-serif; font-size: 12px; line-height: 1.5; color: #9aa0a6;">
            Вы получили это письмо, потому что согласились получать новости Общества ревнителей санскрита.<br>
            <a href="{{ $unsubscribeUrl }}" style="color: #9aa0a6; text-decoration: underline;">Отписаться от рассылки</a>
            · письма об оплате и доступе к урокам продолжат приходить.
        </td>
    </tr>
</table>
@endif
