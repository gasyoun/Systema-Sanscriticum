<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $decision === 'yes' ? 'Доступ к «Спроси Сократа» включен' : 'Согласие не дано' }}</title>
    <style>
        body { margin:0; padding:32px 16px; background:#fcf9f2; color:#3e3a37; font:17px/1.6 Georgia, 'Times New Roman', serif; }
        .card { max-width:640px; margin:0 auto; background:#fff; border-top:6px solid #d35400; border-radius:8px; padding:32px; box-shadow:0 4px 18px rgba(0,0,0,.05); }
        h1 { margin:0 0 8px; font-size:23px; font-weight:normal; color:#8a3324; }
        p { margin:0 0 12px; }
        .box { background:#fffaf3; border:1px solid #f0e6d2; border-radius:8px; padding:16px 18px; margin:16px 0; font-size:15px; }
        .btn { display:inline-block; border-radius:6px; padding:14px 26px; font:bold 15px Georgia, serif; color:#fff; background:#d35400; text-decoration:none; }
        .muted { color:#6b615a; font-size:14px; }
        a { color:#d35400; }
    </style>
</head>
<body>
<div class="card">
    @if($decision === 'yes')
        <h1>Готово: доступ к «Спроси Сократа» включен</h1>
        <p>
            {{ $user->greetingName('друг') }}, согласие записано. Вкладка «Спроси Сократа» уже видна на странице
            любого занятия — справа от записи. Открыть можно с любого устройства: доступ привязан к вашему
            кабинету, а не к этому браузеру.
        </p>
        <p class="box">
            На день дается {{ (int) $invite['daily_requests'] }} запросов; готовые пересказы и неудачные запросы
            лимит не тратят. Тест идет до {{ \Illuminate\Support\Carbon::parse($invite['test_ends_on'])->format('d.m.Y') }},
            после теста ничего не спишется автоматически. Ответ помощника — подсказка, а не слова преподавателя:
            спорное сверяйте с записью.
        </p>
        <p>
            Ошибся или придумал — под уроком есть кнопка «Куратору»: напишите, какой урок, что спросили и в чем
            ошибка.
        </p>
        <p style="margin-top:20px;">
            <a class="btn" href="https://samskrte.ru/dvaram">Открыть кабинет</a>
        </p>
    @else
        <h1>Согласие не дано</h1>
        <p>
            {{ $user->greetingName('друг') }}, мы записали отказ и ничего не меняем: доступ к кабинету, урокам и
            оплатам остается прежним, цена — тоже. Разбирать ваши вопросы к помощнику мы не будем.
        </p>
        <p class="box">
            Передумать можно в любой момент — ответьте на письмо или напишите куратору, и мы пришлем ссылку
            заново.
        </p>
        <p>
            <a class="btn" href="https://samskrte.ru/dvaram">Вернуться в кабинет</a>
        </p>
    @endif

    <p class="muted" style="margin-top:22px;">
        Отозвать решение можно ответом на письмо или через куратора. Журнал согласий хранит дату, канал и
        редакцию текста, на который вы отвечали.
    </p>
</div>
</body>
</html>
