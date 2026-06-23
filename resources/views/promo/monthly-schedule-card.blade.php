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
            height: {{ $canvasHeight }}px;
        }
        .card { position: relative; min-height: {{ $canvasHeight }}px; padding: 52px 56px 36px; }

        /* Фирменный «якорь» — оранжевая полоса по верхней кромке */
        .topbar { position: absolute; top: 0; left: 0; right: 0; height: 9px; background: #E85C24; }

        /* Шапка: лого слева, заголовок справа — таблицей (надёжно в DomPDF) */
        .head { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .head td { vertical-align: middle; }
        .logo { height: 80px; }
        .head .title-cell { text-align: right; }
        .eyebrow {
            color: #E85C24; font-size: 14px; font-weight: bold;
            letter-spacing: 2px; text-transform: uppercase;
        }
        h1 { font-size: 38px; line-height: 1.05; margin: 2px 0 2px; color: #101010; }
        .month { font-size: 19px; color: #6b7280; }

        .rule { height: 3px; background: #E85C24; width: 74px; border-radius: 3px; margin: 18px 0 2px; }

        .course { padding: 12px 0; border-bottom: 1px solid #e7e2d8; }
        .c-title { font-size: 20px; font-weight: bold; color: #101010; line-height: 1.3; }
        .c-teacher { font-size: 16px; color: #E85C24; font-weight: bold; }
        .c-sched { font-size: 15px; color: #4b5563; margin-top: 3px; }
        .dot { color: #E85C24; }

        .footer {
            margin-top: 16px; padding-top: 14px; border-top: 1px solid #e7e2d8;
            font-size: 15px; color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="topbar"></div>

        <table class="head">
            <tr>
                <td>
                    @if (! empty($logoBase64))
                        <img class="logo" src="{{ $logoBase64 }}" alt="ОРС">
                    @else
                        <div class="eyebrow">Общество ревнителей санскрита</div>
                    @endif
                </td>
                <td class="title-cell">
                    <h1>Сейчас идут курсы</h1>
                    <div class="month">{{ $month }}</div>
                </td>
            </tr>
        </table>

        <div class="rule"></div>

        @foreach ($courses as $c)
            <div class="course">
                <div class="c-title"><span class="dot">●</span> {{ $c['title'] }}@if (! empty($c['teacher']))<span class="c-teacher"> — {{ $c['teacher'] }}</span>@endif</div>
                @if (! empty($c['schedule']))
                    <div class="c-sched">{{ $c['schedule'] }} (МСК)</div>
                @endif
            </div>
        @endforeach

        <div class="footer">{{ $site }} · подробности и запись — на сайте</div>
    </div>
</body>
</html>
