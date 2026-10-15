{{-- H6347 — выдача webcal/iCal-фида преподавателю/админу (будильник T−10).
     Самодостаточная страница: не тянет студенческий layout, чтобы кабинет
     преподавателя не зависел от студенческого меню. Настройка планшета —
     docs/ops/TEACHER_ALARM_T10_SAMSUNG_RUNBOOK.md. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Календарь занятий — ссылка-фид</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f6f6f4; color: #1c1c1c; margin: 0; padding: 2rem 1rem; }
        .card { max-width: 44rem; margin: 0 auto; background: #fff; border: 1px solid #e5e5e2; border-radius: 1rem; padding: 1.5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
        p.sub { color: #6b6b6b; font-size: .875rem; margin: 0 0 1.25rem; }
        label { display: block; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #6b6b6b; margin: 1rem 0 .25rem; }
        .url { display: block; width: 100%; box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; padding: .6rem .75rem; border: 1px solid #ddd; border-radius: .5rem; background: #fafaf8; word-break: break-all; }
        .row { display: flex; gap: .5rem; margin-top: 1.25rem; flex-wrap: wrap; }
        a.btn, button.btn { display: inline-flex; align-items: center; padding: .6rem 1rem; border-radius: .6rem; font-size: .85rem; font-weight: 700; text-decoration: none; cursor: pointer; border: 1px solid #ddd; background: #f2f2ef; color: #333; }
        a.btn.primary { background: #e85c24; border-color: #e85c24; color: #fff; }
        .note { margin-top: 1.25rem; padding: .75rem 1rem; background: #fff7ed; border: 1px solid #fed7aa; border-radius: .5rem; font-size: .8rem; line-height: 1.5; }
        .ok { color: #15803d; font-size: .85rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>Расписание занятий — подписка на календарь</h1>
    <p class="sub">Ваши занятия (и как студент, и как преподаватель) будут появляться в календаре автоматически. Планшет: Samsung Android — пошаговая настройка в ранбуке <code>docs/ops/TEACHER_ALARM_T10_SAMSUNG_RUNBOOK.md</code>.</p>

    @if(session('feed_token_status'))
        <p class="ok">{{ session('feed_token_status') }}</p>
    @endif

    <label for="webcal">Webcal-ссылка (вставить в Google Calendar → «По URL»)</label>
    <input id="webcal" class="url" type="text" readonly value="{{ $webcalUrl }}" onclick="this.select()">

    <label for="https">HTTPS-ссылка (для приложений, не понимающих webcal)</label>
    <input id="https" class="url" type="text" readonly value="{{ $feedUrl }}" onclick="this.select()">

    <div class="row">
        <a class="btn primary" href="{{ $webcalUrl }}">Добавить в календарь</a>
        <button type="button" class="btn" onclick="navigator.clipboard.writeText(document.getElementById('webcal').value); this.textContent='Скопировано!'">Скопировать ссылку</button>
        <form action="{{ route('teacher.calendar.feed.regenerate') }}" method="POST" onsubmit="return confirm('Старая ссылка перестанет работать — придётся заново подписать календарь на планшете. Продолжить?');">
            @csrf
            <button type="submit" class="btn">Обновить ссылку</button>
        </form>
    </div>

    <div class="note">
        ⚠️ Будильник T−10: подписанные (webcal) календари Google/Samsung Calendar
        <b>игнорируют VALARM</b> из фида. Поставьте <b>дефолт-алерт «10 минут»</b>
        в настройках календаря на планшете (шаги 2–4 в ранбуке
        <code>docs/ops/TEACHER_ALARM_T10_SAMSUNG_RUNBOOK.md</code>) и правило
        MacroDroid/Tasker «громкость на максимум + сигнал».
    </div>
</div>
</body>
</html>
