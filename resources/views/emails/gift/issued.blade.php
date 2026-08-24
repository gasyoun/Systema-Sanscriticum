<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ваш подарочный сертификат</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #6366f1; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; line-height: 1;">🎁</span>
        </div>

        <h2 style="color: #4338ca; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $buyer->name ?? 'друг' }}!</h2>

        <p style="font-size: 17px; text-align: center;">
            Подарочный сертификат <strong>«{{ $certificate->grantsLabel() }}»</strong> оформлен и готов к передаче.
        </p>

        <div style="background-color: #eef2ff; border-left: 4px solid #6366f1; padding: 20px; margin: 28px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0 0 10px; font-size: 14px;">Одноразовый код активации (передайте его получателю вместе с PDF):</p>
            <p style="margin: 0; font-size: 22px; font-weight: bold; font-family: 'Courier New', monospace; letter-spacing: 2px; text-align: center; color: #1f2430;">{{ $code }}</p>
        </div>

        <p style="font-size: 15px;">
            Получателю нужно:
        </p>
        <ol style="font-size: 15px; padding-left: 20px;">
            <li>Зайти или создать аккаунт на samskrte.ru.</li>
            <li>Открыть страницу <a href="{{ $activateUrl }}">активации сертификата</a>.</li>
            <li>Ввести код — доступ откроется сразу.</li>
        </ol>

        <p style="font-size: 14px; color: #7a7466;">
            Красивый PDF-сертификат приложен к этому письму. Подлинность сертификата получатель
            или вы можете проверить по номеру <strong>{{ $certificate->number }}</strong>:
            <a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a>.
        </p>

        <div style="text-align: center; margin-top: 24px;">
            <a href="{{ $activateUrl }}" style="color: #4338ca; font-size: 14px;">Активировать сертификат →</a>
        </div>

        <hr style="border: none; border-top: 1px solid #eceafd; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
