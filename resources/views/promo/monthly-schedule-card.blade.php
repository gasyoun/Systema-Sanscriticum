<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'DejaVu Sans', sans-serif;
            background: #faf7f2;
            color: #1f2937;
            width: 1100px;
            height: 770px;
        }
        .card { padding: 56px 64px; height: 770px; position: relative; }
        .bar { height: 8px; background: #E85C24; border-radius: 4px; width: 120px; margin-bottom: 28px; }
        .eyebrow { color: #E85C24; font-size: 18px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        h1 { font-size: 38px; line-height: 1.15; margin: 10px 0 6px; color: #101010; }
        .month { font-size: 22px; color: #6b7280; margin-bottom: 28px; }
        .course { padding: 14px 0; border-bottom: 1px solid #e7e2d8; }
        .course:last-child { border-bottom: none; }
        .c-title { font-size: 24px; font-weight: bold; color: #101010; }
        .c-teacher { font-size: 19px; color: #E85C24; }
        .c-sched { font-size: 18px; color: #4b5563; margin-top: 4px; }
        .footer { position: absolute; bottom: 36px; left: 64px; right: 64px; font-size: 17px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        <div class="bar"></div>
        <div class="eyebrow">Общество ревнителей санскрита</div>
        <h1>Сейчас идут курсы</h1>
        <div class="month">{{ $month }}</div>

        @foreach ($courses as $c)
            <div class="course">
                <div class="c-title">{{ $c['title'] }}@if (! empty($c['teacher']))<span class="c-teacher"> — {{ $c['teacher'] }}</span>@endif</div>
                @if (! empty($c['schedule']))
                    <div class="c-sched">🗓 {{ $c['schedule'] }} (МСК)</div>
                @endif
            </div>
        @endforeach

        <div class="footer">{{ $site }} · подробности и запись — на сайте</div>
    </div>
</body>
</html>
