{{-- H3542: связывание Telegram с личным кабинетом по ссылке-приглашению из
     DM саппорт-бота. Standalone-страница без зависимости от кабинетного layout:
     сюда приходят в том числе люди без аккаунта. Единый вид ответа, независимо
     от того, был ли аккаунт и когда именно произошла привязка. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Связывание Telegram с кабинетом</title>
    <style>
        body { font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: #f6f4ef;
               color: #2d2a26; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .card { background: #fff; max-width: 30rem; margin: 1.5rem; padding: 2rem 2.25rem; border-radius: 14px;
                box-shadow: 0 8px 30px rgba(45, 42, 38, .08); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        p { line-height: 1.55; }
        input[type=email] { width: 100%; box-sizing: border-box; padding: .65rem .8rem; font-size: 1rem;
               border: 1px solid #ccc7bd; border-radius: 8px; margin: .4rem 0 1rem; }
        button { background: #e85c24; color: #fff; border: 0; border-radius: 999px; padding: .75rem 1.8rem;
                 font-size: 1rem; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: .5px; }
        small { color: #6b665e; }
    </style>
</head>
<body>
<div class="card">
    @if ($state === 'success')
        <h1>Готово — Telegram связан с кабинетом</h1>
        <p>Спасибо! Ваш Telegram теперь связан с личным кабинетом школы.</p>
        <p>Вернитесь в Telegram и напишите вопрос снова — бот ответит сразу.</p>
        <p><small>Если у вас уже был кабинет, он остался прежним: входите как обычно или через ссылку восстановления пароля.</small></p>
    @else
        <h1>Свяжите Telegram с личным кабинетом</h1>
        <p>Укажите почту, на которую у вас аккаунт школы (или создайте новый — это бесплатно). После этого бот начнёт отвечать в Telegram сам.</p>
        @if (session('support_linked'))
            <div style="background:#eef7ee;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;">
                Связывание выполнено. Вернитесь в Telegram — задайте вопрос ещё раз.
            </div>
        @endif
        <form method="POST" action="{{ route('support.telegram.link.submit', ['token' => $token]) }}">
            @csrf
            <label for="email">Ваша почта</label>
            <input id="email" type="email" name="email" required autofocus placeholder="you@example.com">
            <button type="submit">Связать</button>
        </form>
        <p><small>Почта нужна только для связи с вашим кабинетом. Мы не пишем по ней без вашего согласия.</small></p>
    @endif
</div>
</body>
</html>
