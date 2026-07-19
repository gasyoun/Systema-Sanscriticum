<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Как идут занятия?</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    <span style="display:none; max-height:0; overflow:hidden;">Если уже занимаетесь — просто проигнорируйте это письмо.</span>

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <h2 style="color: #8a3324; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 17px;">Прошло несколько дней с оплаты курса <strong>«{{ $course->title ?? 'санскрита' }}»</strong>. Если вы уже занимаетесь — просто проигнорируйте это письмо.</p>

        <p style="font-size: 17px;">Если еще не начали — это обычное дело: начало откладывается чаще всего. Первый урок по-прежнему ждет в кабинете, и десяти минут хватит, чтобы сдвинуться с места.</p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ url('/login') }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">В личный кабинет</a>
        </div>

        <p style="font-size: 16px;">А если начать мешает что-то конкретное — не открывается урок, непонятно, куда нажать, не находится время — напишите нам, поможем разобраться и подскажем, с чего начать именно вам.</p>

        <div style="text-align: center; margin: 24px 0 8px;">
            <a href="https://t.me/rusamskrtam" style="color: #d35400; font-weight: bold; font-size: 16px;">Написать в Telegram</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 35px 0 25px;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Обычно отвечаем в течение рабочего дня.<br><br>
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>
</body>
</html>
