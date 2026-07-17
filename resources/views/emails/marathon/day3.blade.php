<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Живая консультация Дня 3</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">
    @if ($paid)
        <span style="display:none; max-height:0; overflow:hidden;">{{ $date }}, Zoom. Личный разбор — как договаривались.</span>
    @else
        <span style="display:none; max-height:0; overflow:hidden;">Разбор вопросов участников и подбор маршрутов.</span>
    @endif

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <p style="font-size: 17px;">Здравствуйте!</p>

        @if ($paid)
            <p style="font-size: 17px;">{{ $date }} пройдет живая консультация: ведет {{ $host }}. Ваш вопрос из Дня 2 уже у него — на встрече он будет разобран лично, и вы вместе подберете маршрут.</p>

            <div style="text-align: center; margin: 32px 0;">
                <a href="{{ $link }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block;">Ссылка на Zoom</a>
            </div>

            <p style="font-size: 17px;">Если не сможете быть в эфире — не страшно: запись придет сюда же, а ваш вопрос все равно будет разобран.</p>

            <p style="font-size: 17px;">До встречи,<br>Общество ревнителей санскрита</p>
        @else
            <p style="font-size: 17px;">{{ $date }} пройдет живая консультация с {{ $host }}: разбор вопросов участников и подбор учебных маршрутов. На бесплатном треке участие — в записи: после эфира мы пришлем ссылку в это же письмо-цепочку и в Telegram.</p>

            <p style="font-size: 17px;">Ничего делать не нужно — запись найдет вас сама.</p>

            <p style="font-size: 17px;">Общество ревнителей санскрита</p>
        @endif

    </div>
</body>
</html>
