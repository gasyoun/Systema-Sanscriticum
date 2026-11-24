<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Согласие на участие в тесте «Спроси Сократа»</title>
    <style>
        body { margin:0; padding:32px 16px; background:#fcf9f2; color:#3e3a37; font:17px/1.6 Georgia, 'Times New Roman', serif; }
        .card { max-width:640px; margin:0 auto; background:#fff; border-top:6px solid #d35400; border-radius:8px; padding:32px; box-shadow:0 4px 18px rgba(0,0,0,.05); }
        h1 { margin:0 0 6px; font-size:24px; font-weight:normal; color:#8a3324; }
        .sub { margin:0 0 22px; font-size:14px; color:#95a5a6; }
        h2 { margin:26px 0 8px; font-size:17px; color:#8a3324; }
        p { margin:0 0 12px; }
        ul { margin:0 0 12px; padding-left:22px; }
        li { margin:0 0 5px; }
        .box { background:#fffaf3; border:1px solid #f0e6d2; border-radius:8px; padding:16px 18px; margin:18px 0; font-size:15px; }
        .btn { display:inline-block; border:0; border-radius:6px; padding:14px 26px; font:bold 15px Georgia, serif; color:#fff; background:#d35400; cursor:pointer; }
        .btn-secondary { display:inline-block; border:1px solid #d8cfc4; border-radius:6px; padding:13px 24px; font:bold 15px Georgia, serif; color:#6b615a; background:#fff; cursor:pointer; }
        .row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin:14px 0 0; }
        .check { display:flex; gap:10px; align-items:flex-start; font-size:15px; margin:16px 0 0; }
        .check input { margin-top:4px; width:18px; height:18px; }
        .muted { color:#6b615a; font-size:14px; }
        .err { background:#fdecea; border:1px solid #f5c2bd; color:#8a3324; border-radius:6px; padding:12px 14px; font-size:15px; margin:0 0 14px; }
        a { color:#d35400; }
        hr { border:0; border-top:1px solid #f0e6d2; margin:26px 0; }
        footer { max-width:640px; margin:18px auto 0; text-align:center; font-size:13px; color:#95a5a6; }
    </style>
</head>
<body>
<div class="card">
    <h1>Согласие на участие в тесте «Спроси Сократа»</h1>
    <p class="sub">{{ $user->greetingName('друг') }} · редакция текста от {{ \Illuminate\Support\Carbon::parse($consentVersion)->format('d.m.Y') }}</p>

    @if($errors->any())
        <p class="err">{{ $errors->first() }}</p>
    @endif

    @if($intent === 'no')
        <p class="box">
            Вы открыли страницу из ссылки «Нет, спасибо» — кнопка отказа внизу, ничего не изменится.
        </p>
    @endif

    @if($alreadyTester)
        <p class="box">
            Доступ к помощнику у вас уже включен — согласие можно дать повторно, это ничего не сломает.
        </p>
    @endif

    <p>
        Мы проверяем ИИ-помощника занятия («Спроси Сократа») на живых вопросах учеников. Чтобы разбирать
        ваши вопросы и сообщения об ошибках и по ним править ответы, нужно ваше согласие на обработку
        персональных данных.
    </p>

    <h2>Что обрабатывается</h2>
    <ul>
        <li>имя и адрес электронной почты вашего кабинета;</li>
        <li>текст ваших вопросов ИИ-помощнику занятия;</li>
        <li>текст заметок к занятиям, которые вы делаете в кабинете;</li>
        <li>сообщения об ошибках помощника, которые вы оставите куратору.</li>
    </ul>

    <h2>Зачем</h2>
    <p>
        Только чтобы проверять и улучшать ИИ-помощника занятия: находить неверные ответы, поправлять базу
        знаний и формулировки. Мы не используем эти данные для рекламы, не передаем их третьим лицам и не
        публикуем. Обработка идет в соответствии с
        <a href="https://samskrte.ru/docs/privacy.pdf" target="_blank" rel="noopener">политикой конфиденциальности</a>
        и
        <a href="https://samskrte.ru/docs/soglasie-pd.pdf" target="_blank" rel="noopener">согласием на обработку данных</a>.
    </p>

    <h2>Условия теста</h2>
    <p>
        Тест идет две недели — с {{ \Illuminate\Support\Carbon::parse($invite['test_starts_on'])->format('d.m.Y') }}
        по {{ \Illuminate\Support\Carbon::parse($invite['test_ends_on'])->format('d.m.Y') }} — и он бесплатный.
        После теста ничего не спишется автоматически. После официального релиза помощник будет стоить
        {{ (int) $invite['price_rub_per_month'] }} ₽ в месяц; состав тарифа опубликуем к релизу.
    </p>

    <h2>Что будет по кнопке «Нет»</h2>
    <p>
        Ничего не изменится: согласие добровольное, отказ не влияет ни на доступ к кабинету и урокам, ни на
        цену, ни на отношение кураторов. Но и разбирать ваши вопросы мы тогда не будем — только это.
    </p>

    <hr>

    <form method="POST" action="{{ $confirmUrl }}">
        @csrf
        <input type="hidden" name="decision" value="yes">
        <label class="check">
            <input type="checkbox" name="confirm" value="1" required>
            <span>
                Я прочитал(а) текст выше и даю согласие на обработку перечисленных данных в целях проверки и
                улучшения ИИ-помощника занятия. Согласие действует до отзыва.
            </span>
        </label>
        <div class="row">
            <button type="submit" class="btn">Да, согласен(на) — участвую</button>
        </div>
    </form>

    <form method="POST" action="{{ $confirmUrl }}" style="margin-top:18px;">
        @csrf
        <input type="hidden" name="decision" value="no">
        <button type="submit" class="btn-secondary">Нет, спасибо</button>
    </form>

    <p class="muted" style="margin-top:18px;">
        Отозвать согласие можно в любой момент: ответом на письмо, через куратора или в настройках кабинета.
        Отзыв не отнимает уже купленные курсы и доступ к урокам.
    </p>
</div>

<footer>
    Общество ревнителей санскрита · <a href="https://samskrte.ru">samskrte.ru</a><br>
    Если вы не просили это письмо, просто закройте страницу — ничего не произойдет.
</footer>
</body>
</html>
