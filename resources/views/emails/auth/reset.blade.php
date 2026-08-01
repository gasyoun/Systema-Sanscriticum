<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Вход в личный кабинет ОРС</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 64px; color: #d35400; line-height: 1;">ॐ</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 26px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 18px; text-align: center;">Вам открыт (или вы запросили) доступ в <strong>личный кабинет Общества ревнителей санскрита</strong>. Задайте пароль по ссылке ниже — и войдите.</p>

        <p style="font-size: 18px;">Нажмите кнопку, чтобы задать пароль и войти в кабинет:</p>

        <div style="text-align: center; margin-top: 35px; margin-bottom: 30px;">
            <a href="{{ $resetUrl }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Войти в кабинет</a>
        </div>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 16px 20px; margin: 30px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0; font-size: 15px;">⏳ Ссылка действительна <strong>60 минут</strong>. Если кнопка не работает, скопируйте адрес в браузер:</p>
            <p style="margin: 10px 0 0; font-size: 13px; word-break: break-all; color: #d35400;">{{ $resetUrl }}</p>
        </div>

        <p style="font-style: italic; color: #7f8c8d; font-size: 15px; text-align: center;">Если вы не запрашивали доступ — просто проигнорируйте это письмо, ваш пароль не изменится.</p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 40px 0;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>

</body>
</html>
