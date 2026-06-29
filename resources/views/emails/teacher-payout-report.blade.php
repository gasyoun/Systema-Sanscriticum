<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Расчёт выплаты за блок</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: Arial, sans-serif;">
@php
    $b = $payout?->breakdown ?? [];
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';
    $symbol = \App\Models\TeacherPayout::currencySymbol($payout?->payout_currency);
    $period = null;
    $bp = $b['block_period'] ?? null;
    if ($bp && (($bp['starts_at'] ?? null) || ($bp['ends_at'] ?? null))) {
        $period = (\Illuminate\Support\Carbon::parse($bp['starts_at'])->format('d.m.Y') ?? '…')
            . ' – ' . (\Illuminate\Support\Carbon::parse($bp['ends_at'])->format('d.m.Y') ?? '…');
    }
@endphp
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

                    <tr>
                        <td style="background-color: #E85C24; padding: 28px 30px; text-align: center;">
                            <h2 style="color: #ffffff; margin: 0; font-size: 21px; line-height: 1.3; font-weight: bold;">
                                Общество ревнителей санскрита
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 36px 40px; color: #1A1A1A; font-size: 16px; line-height: 1.6;">
                            <p style="margin: 0 0 20px;">Расчёт выплаты за блок
                                <strong>№{{ $b['block_number'] ?? '—' }}</strong>@if ($payout?->course?->title) курса «<strong>{{ $payout->course->title }}</strong>»@endif@if ($period) ({{ $period }})@endif:</p>

                            @php
                                $students = (int) ($b['student_count'] ?? 0);
                                // За студента: для фикс-модели — оговорённая ставка, иначе выводим из
                                // итога ÷ число слушателей, чтобы препод видел состав суммы.
                                $perStudent = null;
                                if ($students > 0) {
                                    $perStudent = (($b['salary_type'] ?? null) === 'fix_per_student' && ($b['fixed_rate'] ?? 0) > 0)
                                        ? (float) $b['fixed_rate']
                                        : round((float) $payout?->amount / $students);
                                }
                            @endphp
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 15px; line-height: 1.6;">
                                @if ($students > 0)
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">Слушателей в блоке</td>
                                        <td style="padding: 6px 0; text-align: right; font-weight: bold;">{{ $students }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">За слушателя</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $money($perStudent) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">{{ $students }} × {{ $money($perStudent) }}</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $money($students * $perStudent) }}</td>
                                    </tr>
                                @else
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">Расчёт</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $payout?->comment }}</td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding: 12px 0 6px; color: #555; border-top: 1px solid #eee;">Итого в рублях</td>
                                    <td style="padding: 12px 0 6px; text-align: right; font-weight: bold; border-top: 1px solid #eee;">{{ $money($payout?->amount) }}</td>
                                </tr>

                                @if ($payout?->amount_foreign && $payout?->payout_currency)
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">Курс PayPal{{ $payout?->rate_date ? ' на '.$payout->rate_date->format('d.m.Y') : '' }}</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ rtrim(rtrim(number_format((float) $payout->exchange_rate, 4, '.', ' '), '0'), '.') }} ₽ / {{ $symbol }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 10px 0; color: #1A1A1A; font-size: 18px; font-weight: bold;">К выплате</td>
                                        <td style="padding: 10px 0; text-align: right; color: #E85C24; font-size: 18px; font-weight: bold;">{{ number_format((float) $payout->amount_foreign, 2, '.', ' ') }} {{ $symbol }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 40px 32px; color: #9aa0a6; font-size: 12px; line-height: 1.5;">
                            Если есть вопросы по расчёту — ответьте на это письмо.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
