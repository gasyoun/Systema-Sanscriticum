<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>С чего начать: первый урок уже открыт</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    <span style="display:none; max-height:0; overflow:hidden;">Один короткий шаг сегодня — и курс перестает быть «отложенным».</span>

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <h2 style="color: #8a3324; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}!</h2>

        <p style="font-size: 17px;">Вчера вы оплатили курс <strong>«{{ $course->title ?? 'санскрита' }}»</strong> — самое подходящее время сделать первый шаг. Он маленький: откройте первый урок в личном кабинете. Не обязательно проходить его целиком — десяти минут достаточно, чтобы началось.</p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ url('/login') }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Открыть первый урок</a>
        </div>

        @if(!empty($course?->chat_url))
        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 25px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0 0 12px; font-size: 16px;">В чате курса — расписание, анонсы и одногруппники. Загляните, там живые люди.</p>
            <p style="margin: 0;"><a href="{{ $course->chat_url }}" style="color: #d35400; font-weight: bold;">Войти в чат курса</a></p>
        </div>
        @endif

        <p style="font-size: 16px;">Никакого расписания сверху: ваш темп — ваш. Но первый шаг легче всего дается в первые дни.</p>

        {{-- MG 23-08-2026: surfacing self-serve кабинета — единственный контакт до входа это письмо. --}}
        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 20px; margin: 25px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0 0 10px; font-size: 16px;"><strong>Кабинет сам умеет:</strong></p>
            <p style="margin: 0 0 6px; font-size: 15px;">— погасить долг или взнос по рассрочке, без куратора;<br>
            — показать, почему урок закрыт, и открыть оплаченные блоки одной кнопкой;<br>
            — подсказать на каждом экране: пошаговый гид со скриншотами.</p>
            <p style="margin: 0; font-size: 15px;">Весь гид: <a href="https://samskrte.ru/dvaram/help" style="color: #d35400; font-weight: bold;">samskrte.ru/dvaram/help</a></p>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 35px 0 25px;">

        <p style="margin-top: 0; font-size: 15px; color: #95a5a6; text-align: center;">
            Вопросы — <a href="https://t.me/rusamskrtam" style="color: #d35400;">напишите нам в Telegram</a>, обычно отвечаем в течение рабочего дня.<br><br>
            <strong>Общество ревнителей санскрита</strong>
        </p>

    </div>
</body>
</html>
