<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Спасибо за оплату курса</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 64px; color: #d35400; line-height: 1;">ॐ</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 26px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 18px; text-align: center;">
            Благодарим за оплату курса <strong>«{{ $course->title }}»</strong>. Доступ к материалам уже открыт в вашем личном кабинете — можно приступать к занятиям.
        </p>

        @if(!empty($course->chat_url))
            <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 30px 0; border-radius: 0 4px 4px 0;">
                <p style="margin: 0; font-size: 16px;">
                    Присоединяйтесь к чату курса — там анонсы, расписание занятий и общение с одногруппниками и преподавателем.
                </p>
            </div>
            <div style="text-align: center; margin: 8px 0 28px;">
                <a href="{{ $course->chat_url }}" style="background-color: #f59e0b; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Войти в чат курса</a>
            </div>
            <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $course->chat_url }}</p>
        @endif

        <div style="text-align: center; margin-top: 32px; margin-bottom: 30px;">
            <a href="{{ url('/login') }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">В личный кабинет</a>
        </div>

        @if(config('surveys.enabled'))
            <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 30px 0; border-radius: 0 4px 4px 0;">
                <p style="margin: 0 0 12px; font-size: 16px;">
                    Первый шаг — две минуты на пару вопросов о вас. Это поможет куратору подобрать вам группу и темп занятий.
                </p>
                <div style="text-align: center;">
                    <a href="{{ url('/anketa/onboarding') }}" style="color: #8a3324; font-weight: bold; text-decoration: underline;">Пройти короткую анкету</a>
                </div>
            </div>
        @endif

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 40px 0;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Если у вас возникнут вопросы, просто ответьте на это письмо — мы поможем.<br><br>
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>

</body>
</html>
