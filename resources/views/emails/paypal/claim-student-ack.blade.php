<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Заявка получена</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    <span style="display:none; max-height:0; overflow:hidden;">@if($trusted)Доступ к курсу открыт.@elseСверим платеж — обычно в течение одного рабочего дня — и откроем доступ.@endif</span>

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <h2 style="color: #8a3324; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 17px;">Ваше уведомление об оплате через PayPal получено — вот что мы записали:</p>

        <table style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 16px;">
            <tr>
                <td style="padding: 8px 0; color: #95a5a6; width: 150px;">Курс</td>
                <td style="padding: 8px 0;"><strong>«{{ $course->title ?? 'Обучающий материал' }}»</strong></td>
            </tr>
            @if ($tariffScope)
            <tr>
                <td style="padding: 8px 0; color: #95a5a6;">Тариф</td>
                <td style="padding: 8px 0;">{{ $tariffScope }}</td>
            </tr>
            @endif
            @if ($claimedAmount)
            <tr>
                <td style="padding: 8px 0; color: #95a5a6;">Заявленная сумма</td>
                <td style="padding: 8px 0;">{{ $claimedAmount }}</td>
            </tr>
            @endif
        </table>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 25px 0; border-radius: 0 4px 4px 0;">
            @if ($trusted)
            <p style="margin: 0 0 12px; font-size: 16px;">
                Вы наш ученик, поэтому доступ к курсу открыт сразу — без ожидания
                сверки. Мы все равно сверим платеж по вашим данным; если что-то
                не сойдется, напишем вам.
            </p>
            <p style="margin: 0; font-size: 16px;">
                Если деньги списались — не платите повторно: напишите нам, мы проверим платеж
                и либо откроем доступ, либо вернем деньги.
            </p>
            @else
            <p style="margin: 0 0 12px; font-size: 16px;">
                Платеж сверяем вручную: PayPal не поддерживает автосписание на нашей
                платформе. Обычно сверка занимает не больше одного рабочего дня — как только
                она пройдет, доступ откроется. Для нового аккаунта пароль придет на email
                отдельным письмом.
            </p>
            <p style="margin: 0; font-size: 16px;">
                Если деньги списались — не платите повторно: напишите нам, мы проверим платеж
                и либо откроем доступ, либо вернем деньги.
            </p>
            @endif
        </div>

        @if ($trusted)
        <p style="font-size: 16px;">
            Уроки и материалы ждут вас в личном кабинете.
        </p>
        {{-- MG 23-08-2026: surfacing self-serve кабинета в момент первой PayPal-заявки своего ученика. --}}
        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 25px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0 0 10px; font-size: 16px;"><strong>Кабинет сам умеет:</strong></p>
            <p style="margin: 0 0 6px; font-size: 15px;">— внести очередную часть оплаты или закрыть просроченный платеж — без куратора;<br>
            — показать, почему урок закрыт, и открыть оплаченные блоки одной кнопкой;<br>
            — подсказать на каждом экране: пошаговый гид со скриншотами.</p>
            <p style="margin: 0; font-size: 15px;">Весь гид: <a href="https://samskrte.ru/dvaram/help" style="color: #d35400; font-weight: bold;">samskrte.ru/dvaram/help</a></p>
        </div>
        @else
        <p style="font-size: 16px;">
            Если рабочий день прошел, а доступа нет — <a href="https://t.me/rusamskrtam" style="color: #d35400;">напишите нам в Telegram</a>.
        </p>
        @endif

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 35px 0 25px;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Вопросы — <a href="https://t.me/rusamskrtam" style="color: #d35400;">напишите нам в Telegram</a>, обычно отвечаем в течение рабочего дня.<br><br>
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>
</body>
</html>
