{{--
    H3084, шаг 16 — акт сверки с преподавателем по семье потоков.

    Одна страница. Внизу отдельная ПУСТАЯ строка «решение о доплате сверх
    остатка»: решение человека не должно раствориться в расчёте (§4 PLAN).
    Шрифт DejaVu — иначе dompdf не покажет кириллицу.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Акт сверки — {{ $teacherName }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        .sub { color: #555; font-size: 9pt; margin-bottom: 7mm; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
        th, td { border: 0.4pt solid #999; padding: 2mm 2.5mm; text-align: left; vertical-align: top; }
        th { background: #f1f1f1; font-size: 9pt; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; background: #fafafa; }
        .note { font-size: 8.5pt; color: #666; margin-top: -3mm; margin-bottom: 6mm; }
        .decision { border: 0.8pt solid #333; padding: 4mm; margin-top: 8mm; }
        .decision .label { font-size: 9pt; font-weight: bold; margin-bottom: 3mm; }
        .decision .blank { border-bottom: 0.4pt solid #666; height: 7mm; margin-bottom: 4mm; }
        .sign { margin-top: 10mm; font-size: 9pt; }
        .sign td { border: 0; padding: 0 0 6mm; }
        .warn { color: #8a5a00; font-size: 9pt; }
    </style>
</head>
<body>

<h1>Акт сверки по вознаграждению преподавателя</h1>
<div class="sub">
    {{ $teacherName }} · семья потоков «{{ $familyTitle }}» · сформировано {{ $generatedOn }}
</div>

<table>
    <thead>
    <tr>
        <th>Поток</th>
        <th class="num">Выручка, ₽</th>
        <th>Ставка</th>
        <th class="num">Начислено, ₽</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($streams as $s)
        <tr>
            <td>
                {{ $s['title'] }}
                @if ($s['is_recording'])
                    <br><span class="warn">записи прошлого потока</span>
                @endif
            </td>
            <td class="num">{{ $money($s['revenue']) }}</td>
            <td>{{ $s['scheme'] }}</td>
            <td class="num">{{ $money($s['accrued']) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Итого начислено</td>
        <td class="num">{{ $money($revenueTotal) }}</td>
        <td></td>
        <td class="num">{{ $money($accrued) }}</td>
    </tr>
    </tbody>
</table>
<div class="note">
    Начисление считается от валовой выручки потока: платежи-«Расходы» рассматриваются
    как выплаты преподавателю и из базы начисления не вычитаются.
</div>

<table>
    <thead>
    <tr>
        <th>Выплачено</th>
        <th>Дата</th>
        <th>Основание</th>
        <th class="num">Сумма, ₽</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($paidLines as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td>{{ $line['date'] ?? '—' }}</td>
            <td>{{ $line['note'] }}</td>
            <td class="num">{{ $money($line['amount']) }}</td>
        </tr>
    @empty
        <tr><td colspan="4">Подтверждённых выплат по этой семье потоков нет.</td></tr>
    @endforelse
    <tr class="total">
        <td colspan="3">Итого выплачено</td>
        <td class="num">{{ $money($paidOut) }}</td>
    </tr>
    <tr class="total">
        <td colspan="3">
            Остаток
            @unless ($attributionConfirmed)
                <span class="warn">(предварительно)</span>
            @endunless
        </td>
        <td class="num">{{ $money($remainder) }}</td>
    </tr>
    </tbody>
</table>

@unless ($attributionConfirmed)
    <div class="note">
        Остаток предварительный: {{ count($pending) }}
        {{ \App\Support\Plural::ru(count($pending), 'платёж', 'платежа', 'платежей') }}
        на {{ $money($pendingTotal) }} ₽ проведены по этим курсам как «Расход» и ещё не размечены.
        Если подтвердить все, остаток станет {{ $money($remainderIfAllConfirmed) }} ₽.
    </div>
@endunless

<div class="decision">
    <div class="label">Решение о доплате сверх остатка</div>
    <div class="blank"></div>
    <div class="blank"></div>
</div>

<table class="sign">
    <tr>
        <td width="50%">Преподаватель ____________________ / {{ $teacherName }} /</td>
        <td width="50%">Со стороны школы ____________________ /____________________/</td>
    </tr>
</table>

</body>
</html>
