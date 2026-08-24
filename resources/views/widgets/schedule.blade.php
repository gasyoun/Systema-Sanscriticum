<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Расписание занятий</title>
    {{-- H1427, wave 1b: голый встраиваемый виджет. Никакого @vite/layout сайта — --}}
    {{-- страница остаётся zero-dependency, стили инлайн, скрипт — обычный vanilla JS. --}}
    <style>
        :root {
            --fg: #1f2430;
            --muted: #6b7280;
            --line: #e5e7eb;
            --accent: #2f6feb;
            --recruiting-bg: #eaf7ee;
            --recruiting-fg: #1c7c3c;
            --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: var(--font);
            color: var(--fg);
            background: transparent;
            font-size: 15px;
            line-height: 1.45;
            padding: 8px;
        }
        .sw-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }
        .sw-field { display: flex; flex-direction: column; gap: 3px; min-width: 180px; flex: 1; }
        .sw-field label { font-size: 12px; color: var(--muted); }
        .sw-field select {
            font: inherit;
            padding: 7px 9px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--fg);
        }
        .sw-day { margin: 0 0 18px; }
        .sw-day h3 {
            margin: 0 0 8px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
            padding-bottom: 4px;
        }
        .sw-row {
            display: grid;
            grid-template-columns: 62px 1fr auto;
            gap: 10px;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px solid var(--line);
        }
        .sw-time { font-variant-numeric: tabular-nums; font-weight: 600; }
        .sw-title { font-weight: 600; }
        .sw-title a { color: var(--accent); text-decoration: none; }
        .sw-title a:hover { text-decoration: underline; }
        .sw-meta { display: block; font-size: 13px; color: var(--muted); margin-top: 2px; }
        .sw-badge {
            display: inline-block;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--recruiting-bg);
            color: var(--recruiting-fg);
            white-space: nowrap;
        }
        .sw-state { color: var(--muted); padding: 16px 0; }
        .sw-book {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            margin-top: 6px;
        }
        .sw-email-input {
            font: inherit;
            padding: 5px 9px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            color: var(--fg);
            width: 200px;
            max-width: 100%;
        }
        .sw-email-input:focus { outline: 2px solid var(--accent); outline-offset: 0; border-color: var(--accent); }
        .sw-btn {
            font: inherit;
            padding: 6px 14px;
            border: none;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            white-space: nowrap;
        }
        .sw-btn:hover { filter: brightness(1.08); }
        .sw-btn:disabled { opacity: .55; cursor: default; }
        .sw-book-msg { font-size: 13px; }
        .sw-book-msg.is-err { color: #b42318; }
        .sw-book-msg.is-ok { color: var(--recruiting-fg); }
        @media (max-width: 480px) {
            .sw-row { grid-template-columns: 52px 1fr; }
            .sw-badge { grid-column: 2; justify-self: start; margin-top: 4px; }
        }
    </style>
</head>
<body>
    <div class="sw-filters">
        <div class="sw-field">
            <label for="sw-filter-direction">Направление</label>
            <select id="sw-filter-direction">
                <option value="">Все направления</option>
            </select>
        </div>
        <div class="sw-field">
            <label for="sw-filter-teacher">Преподаватель</label>
            <select id="sw-filter-teacher">
                <option value="">Все преподаватели</option>
            </select>
        </div>
    </div>

    <div id="sw-schedule" aria-live="polite">
        <p class="sw-state">Загрузка расписания…</p>
    </div>

    <script src="{{ asset('widgets/schedule.js') }}" data-feed-url="{{ $feedUrl }}" data-book-url="{{ route('api.public.schedule.book') }}"></script>
</body>
</html>
