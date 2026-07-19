<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Бронь перенесена</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #f59e0b; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; line-height: 1;">🔄</span>
        </div>

        <h2 style="color: #b45309; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}! 🙏</h2>

        <p style="font-size: 17px; text-align: center;">
            Ваша бронь перенесена с курса <strong>«{{ $fromCourse->title }}»</strong>
            на курс <strong>«{{ $toCourse->title }}»</strong>.
        </p>

        <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px; margin: 28px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 6px 0; font-size: 16px;">
                Сумма предоплаты <strong>зачтется при оплате нового курса</strong> — доплатить нужно будет только разницу.
            </p>
        </div>

        @if(!empty($toCourse->chat_url))
            <p style="font-size: 16px; text-align: center; margin-bottom: 12px;">
                Присоединяйтесь к чату нового курса:
            </p>
            <div style="text-align: center; margin: 8px 0 28px;">
                <a href="{{ $toCourse->chat_url }}" style="background-color: #f59e0b; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Войти в чат курса</a>
            </div>
            <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $toCourse->chat_url }}</p>
        @endif

        <div style="text-align: center; margin-top: 24px;">
            <a href="{{ url('/login') }}" style="color: #b45309; font-size: 14px;">Перейти в личный кабинет →</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
