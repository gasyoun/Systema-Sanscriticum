<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Отписка от рассылки — Общество ревнителей санскрита</title>
    <style>
        body { margin: 0; padding: 40px 16px; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #fcf9f2; color: #2c2416; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px 28px; border-top: 6px solid #E85C24; box-shadow: 0 4px 15px rgba(0,0,0,.05); }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.55; margin: 0 0 14px; }
        button { border: 0; border-radius: 10px; background: #E85C24; color: #fff; font-weight: 700; font-size: 15px; padding: 12px 22px; cursor: pointer; }
        .muted { color: #6b7280; font-size: 13px; }
        a { color: #E85C24; }
    </style>
</head>
<body>
<div class="card">
    @if($done)
        <h1>Вы отписаны</h1>
        <p>Больше не будем присылать анонсы и новости на <strong>{{ $email }}</strong>.</p>
        <p class="muted">Письма об оплате, доступе к урокам и восстановлении пароля продолжат приходить — они нужны для учёбы.
            Снова подписаться можно в личном кабинете, в разделе «Уведомления и рассылки».</p>
    @else
        <h1>Отписаться от рассылки?</h1>
        <p>Перестанем присылать анонсы, новости и расписание на <strong>{{ $email }}</strong>.</p>
        <form method="POST" action="{{ request()->fullUrl() }}">
            <button type="submit">Отписаться</button>
        </form>
        <p class="muted" style="margin-top:18px;">Письма об оплате и доступе к урокам приходить продолжат.</p>
    @endif
    <p class="muted"><a href="{{ url('/') }}">samskrte.ru</a> · <a href="{{ route('docs.show', 'privacy') }}">Политика конфиденциальности</a></p>
</div>
</body>
</html>
