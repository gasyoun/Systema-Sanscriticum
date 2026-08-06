{{-- Public status helper for students/teachers. Keep text huge-simple (ADHD-friendly). --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,follow">
    <title>Сайт жив? — samskrte.ru</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f7f4ef;
            color: #101010;
            line-height: 1.45;
        }
        .wrap { max-width: 40rem; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
        h1 { font-size: 1.75rem; margin: 0 0 0.5rem; line-height: 1.2; }
        .lead { font-size: 1.1rem; color: #333; margin: 0 0 1.25rem; }
        .card {
            background: #fff;
            border: 1px solid #e5e0d6;
            border-radius: 1rem;
            padding: 1rem 1.1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        .card h2 { font-size: 1.15rem; margin: 0 0 0.75rem; }
        ol, ul { margin: 0; padding-left: 1.25rem; }
        li { margin: 0.45rem 0; }
        a.btn {
            display: block;
            text-align: center;
            text-decoration: none;
            background: #E85C24;
            color: #fff;
            font-weight: 700;
            padding: 0.85rem 1rem;
            border-radius: 0.75rem;
            margin: 0.5rem 0;
        }
        a.btn.secondary { background: #1f2937; }
        a.btn.ghost {
            background: #fff;
            color: #E85C24;
            border: 2px solid #E85C24;
        }
        .ok { color: #0a7a32; font-weight: 700; }
        .bad { color: #b42318; font-weight: 700; }
        .muted { color: #666; font-size: 0.95rem; }
        code, .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.9em;
            background: #f0ebe3;
            padding: 0.1em 0.35em;
            border-radius: 0.25rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        pre.tpl {
            background: #1f2937;
            color: #f9fafb;
            padding: 0.9rem;
            border-radius: 0.75rem;
            overflow-x: auto;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .pill {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            background: #eee;
        }
        .pill.ok { background: #d1fae5; color: #065f46; }
        .pill.bad { background: #fee2e2; color: #991b1b; }
        .pill.wait { background: #fef3c7; color: #92400e; }
        footer { margin-top: 1.5rem; font-size: 0.9rem; color: #666; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Сайт не открывается?</h1>
    <p class="lead">
        Три шага. Сначала проверьте себя (интернет / VPN), потом напишите нам.
    </p>

    <div class="card">
        <h2>Шаг 1 — нажмите эти кнопки</h2>
        <p class="muted">Лучше с <strong>телефона на мобильном интернете</strong> (не Wi‑Fi).</p>
        <a class="btn" href="https://samskrte.ru/" target="_blank" rel="noopener">Открыть главную samskrte.ru</a>
        <a class="btn secondary" href="https://samskrte.ru/login" target="_blank" rel="noopener">Открыть вход /login</a>
        <a class="btn ghost" href="https://samskrtam.ru/" target="_blank" rel="noopener">Открыть samskrtam.ru (книги)</a>
        <p class="muted" style="margin-top:0.75rem">
            Запасная страница, если эта не грузится:
            <a href="https://gasyoun.github.io/Systema-Sanscriticum/uptime/">зеркало на GitHub</a>
        </p>
        <div id="auto-check" class="muted" style="margin-top:0.75rem">
            Автопроверка этой страницы: <span id="self-status" class="pill wait">…</span>
        </div>
    </div>

    <div class="card">
        <h2>Шаг 2 — что это значит</h2>
        <ul>
            <li><strong>На телефоне есть, на компе нет</strong> → чаще Wi‑Fi или <strong>VPN</strong>. Выключите VPN на 1 минуту, другой браузер.</li>
            <li><strong>Нигде нет</strong> (телефон + комп) → скорее наш сайт. Перейдите к шагу 3.</li>
            <li><strong>Главная есть, вход нет</strong> → тоже пишите нам (шаг 3).</li>
        </ul>
    </div>

    <div class="card">
        <h2>Шаг 3 — напишите в Telegram</h2>
        <p>
            Откройте чат школы и <strong>отметьте</strong>
            <a href="https://t.me/rusamskrtam">@rusamskrtam</a>
            (увидят Иван и Марцис).
        </p>
        <p class="muted">Скопируйте текст:</p>
        <pre class="tpl" id="tpl">@rusamskrtam сайт не открывается
где: телефон / комп
VPN: вкл / выкл
что пробовал: samskrte.ru · /login · /uptime
что вижу: (белый экран / ошибка / крутится / другое)
время: сейчас</pre>
        <button type="button" class="btn" id="copy-btn" style="border:0;width:100%;cursor:pointer">Скопировать текст</button>
        <p class="muted" style="margin-top:0.75rem">
            <strong>Не пишите Артёму.</strong> Артёму пишут только Иван или Марцис, если мёртв сервер.
        </p>
    </div>

    <div class="card">
        <h2>Преподавателям</h2>
        <p class="muted">
            Авто-алерт «сайт упал» вам <strong>не</strong> приходит — пишите
            <a href="https://t.me/rusamskrtam">@rusamskrtam</a>.
            Шпаргалка и текст для закрепа:
            <a href="https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_SITE_DOWN_CHEATSHEET_RU.md">шпаргалка</a>
            ·
            <a href="https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/teacher-site-down-telegram-pin.md">pin TG</a>.
        </p>
    </div>

    <div class="card">
        <h2>Для ops (не ученикам)</h2>
        <p class="muted">
            Красные мониторы Better Stack, SSH, Артём —
            <a href="https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md">инструкция RU §2</a>
            ·
            <a href="https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md">EN для агентов</a>
        </p>
    </div>

    <footer>
        Если вы читаете эту страницу на <strong>samskrte.ru</strong> — сам сервер школы сейчас отвечает.
        Страница сгенерирована {{ now()->timezone('Europe/Moscow')->format('d.m.Y H:i') }} MSK.
    </footer>
</div>
<script>
(function () {
    var el = document.getElementById('self-status');
    if (el) {
        el.textContent = 'эта страница открылась — сервер школы отвечает';
        el.className = 'pill ok';
    }
    var btn = document.getElementById('copy-btn');
    var tpl = document.getElementById('tpl');
    if (btn && tpl) {
        btn.addEventListener('click', function () {
            var text = tpl.textContent || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    btn.textContent = 'Скопировано';
                }).catch(function () {
                    btn.textContent = 'Выделите текст вручную';
                });
            } else {
                btn.textContent = 'Выделите текст вручную';
            }
        });
    }
})();
</script>
</body>
</html>
