<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Подтверждение почты</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px;">
        <h2 style="color: #c0531f; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">
            Намасте, {{ $userName ?: 'друг' }}!
        </h2>
        <p style="font-size: 16px;">
            Вы создали личный кабинет через бота. Подтвердите почту — одна ссылка сразу
            подтвердит адрес и пустит вас в кабинет, пароль не понадобится.
        </p>
        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $verifyUrl }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block;">
                Подтвердить почту и войти
            </a>
        </div>
        <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">
            Ссылка одноразовая. Если кнопка не работает, скопируйте: {{ $verifyUrl }}
        </p>
        <p style="font-size: 13px; color: #95a5a6; text-align: center;">
            Если это были не вы — просто проигнорируйте письмо.
        </p>
    </div>
</body>
</html>
