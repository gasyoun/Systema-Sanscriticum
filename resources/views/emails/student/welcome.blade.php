<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Доступ к курсу</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 64px; color: #d35400; line-height: 1;">ॐ</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 26px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'Студент' }}! 🙏</h2>

        <p style="font-size: 18px; text-align: center;">Мы получили вашу оплату и с радостью приветствуем вас в <strong>Обществе ревнителей санскрита</strong>. Ваш путь к изучению священного языка начался.</p>
        
        <p style="font-size: 18px;">Ваш личный кабинет успешно активирован. В нем бережно собраны все необходимые материалы, уроки и доступы.</p>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 30px 0; border-radius: 0 4px 4px 0;">
            <h3 style="margin-top: 0; color: #8a3324; font-size: 18px; font-weight: normal;">Ваши данные для входа:</h3>
            <p style="margin: 8px 0; font-size: 16px;"><strong>Логин (Email):</strong> <span style="color: #d35400;">{{ $user->email }}</span></p>
            <p style="margin: 8px 0; font-size: 16px;"><strong>Пароль:</strong> <span style="color: #d35400;">{{ $password }}</span></p>
        </div>

        <p style="font-style: italic; color: #7f8c8d; font-size: 15px; text-align: center;">Рекомендуем сменить пароль в настройках профиля после первого входа.</p>

        <div style="text-align: center; margin-top: 40px; margin-bottom: 30px;">
            <a href="{{ url('/login') }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Перейти к знаниям</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 40px 0;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Если у вас возникнут вопросы, просто ответьте на это письмо.<br><br>
            С уважением,<br>
            <strong>Команда Общества ревнителей санскрита</strong>
        </p>

    </div>

</body>
</html>