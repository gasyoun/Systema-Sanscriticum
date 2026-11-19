<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>К вашему кабинету привязан Telegram</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 64px; color: #d35400; line-height: 1;">ॐ</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 26px; margin-top: 0; font-weight: normal;">Намасте, {{ $user?->greetingName('друг') ?? 'друг' }}!</h2>

        <p style="font-size: 18px; text-align: center;">К вашему личному кабинету Общества ревнителей санскрита привязан аккаунт Telegram.</p>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 16px 20px; margin: 30px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0; font-size: 15px;">
                Аккаунт Telegram: <strong>{{ $boundUsername ? '@'.$boundUsername : 'без username' }}</strong><br>
                Время привязки: {{ now()->format('d.m.Y в H:i') }} (по Москве)
            </p>
        </div>

        <p style="font-size: 16px;">Теперь одноразовая ссылка для входа в кабинет — всегда под рукой: напишите боту <strong>@samskrtamru_bot</strong> слово <strong>/вход</strong>, и он пришлёт свежую ссылку. Туда же будут приходить напоминания о занятиях.</p>

        <p style="font-size: 16px;">Если привязку делали не вы — <strong>ответьте на это письмо</strong> или напишите куратору: мы немедленно отзовём привязку, и доступ к кабинету вернётся только к вам.</p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 40px 0;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>

</body>
</html>
