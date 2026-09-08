<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Небольшая анкета о вашем пути</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 64px; color: #d35400; line-height: 1;">ॐ</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 26px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 17px;">
            Вы уже учитесь у нас — помогите понять, что действительно повлияло на ваш выбор и что стоит улучшить.
        </p>

        <p style="font-size: 17px;">
            Подробная анкета о вашем пути, покупках и результатах займёт около <strong>15–20 минут</strong>. Нам одинаково полезны хорошие и критические ответы. Участие добровольное, на обучение не влияет. Если уже заполняли нашу анкету в последние три месяца, эту можно пропустить.
        </p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $url }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Заполнить опрос</a>
        </div>

        <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $url }}</p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 40px 0;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Если у вас возникнут вопросы, просто ответьте на это письмо — мы поможем.
        </p>
    </div>
</body>
</html>
