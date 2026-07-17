<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Запись консультации и ваша скидка</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    <span style="display:none; max-height:0; overflow:hidden;">Смотрите когда удобно; скидка без ограничения по сроку.</span>

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <p style="font-size: 17px;">Здравствуйте!</p>

        <p style="font-size: 17px;">Запись живой консультации готова:</p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $recordingLink }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block;">Смотреть запись</a>
        </div>

        <p style="font-size: 17px;">И — как обещали на консультации — за вами закреплена скидка {{ $coupon }} ₽ на любой первый курс. Она без ограничения по сроку: решите начать через неделю или через месяц — просто напишите нам, применим ее к оплате.</p>

        <p style="font-size: 17px;">Пара честных слов о курсах, чтобы решение было спокойным:</p>

        <p style="font-size: 17px;">
            — темп тот же, что в эти три дня: короткие уроки, свой график;<br>
            — записи открыты бессрочно — «отстать навсегда» с них нельзя;<br>
            — оплату можно делить по блокам курса: следующая часть — когда дойдете до следующего блока, а не по календарю;<br>
            — если по вопросу из Дня 2 остались уточнения — ответьте на это письмо, {{ $host }} читает разборы лично.
        </p>

        <p style="font-size: 17px;">Если сейчас не время — это нормально. Курсы никуда не уходят.</p>

        <p style="font-size: 17px;">Общество ревнителей санскрита</p>

    </div>
</body>
</html>
