<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Расчет выплаты за блок</title>
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
                            <p style="margin: 0 0 20px;">Расчет выплаты за блок
                                <strong>№{{ $b['block_number'] ?? '—' }}</strong>@if ($payout?->course?->title) курса «<strong>{{ $payout->course->title }}</strong>»@endif@if ($period) ({{ $period }})@endif:</p>

                            @php
                                // Денежная разбивка считается от числа слушателей, давшего сумму
                                // (для fix_per_student — ручной ввод, иначе число платных).
                                $students = (int) ($b['student_count'] ?? 0);
                                // Состав слушателей блока: платные (оплата > 0) и льготники
                                // (нулевая оплата). Для старых выплат без разбивки — фолбэк на
                                // student_count (все считаем платными).
                                $paidStudents = (int) ($b['paid_student_count'] ?? $b['student_count'] ?? 0);
                                $freeStudents = (int) ($b['free_student_count'] ?? 0);
                                $totalStudents = $paidStudents + $freeStudents;
                                // За студента: для фикс-модели — оговоренная ставка, иначе выводим из
                                // итога ÷ число слушателей, чтобы препод видел состав суммы.
                                $perStudent = null;
                                if ($students > 0) {
                                    $perStudent = (($b['salary_type'] ?? null) === 'fix_per_student' && ($b['fixed_rate'] ?? 0) > 0)
                                        ? (float) $b['fixed_rate']
                                        : round((float) $payout?->amount / $students);
                                }
                            @endphp
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="font-size: 15px; line-height: 1.6;">
                                @if ($totalStudents > 0)
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">Всего студентов</td>
                                        <td style="padding: 6px 0; text-align: right; font-weight: bold;">{{ $totalStudents }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 4px 0 4px 16px; color: #777;">— платных</td>
                                        <td style="padding: 4px 0; text-align: right;">{{ $paidStudents }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 4px 0 4px 16px; color: #777;">— льготников (бесплатно)</td>
                                        <td style="padding: 4px 0; text-align: right;">{{ $freeStudents }}</td>
                                    </tr>
                                @endif
                                @if ($students > 0)
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">За слушателя</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $money($perStudent) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">{{ $students }} × {{ $money($perStudent) }}</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $money($students * $perStudent) }}</td>
                                    </tr>
                                @endif
                                @if ($totalStudents === 0 && $students === 0)
                                    <tr>
                                        <td style="padding: 6px 0; color: #555;">Расчет</td>
                                        <td style="padding: 6px 0; text-align: right;">{{ $payout?->comment }}</td>
                                    </tr>
                                @endif

                                @php $priorBlocks = $b['prior_blocks_paid'] ?? []; @endphp
                                @if (count($priorBlocks) > 0)
                                    <tr>
                                        <td colspan="2" style="padding: 12px 0 4px; color: #555; border-top: 1px solid #eee; font-weight: bold;">Поздние оплаты прошлых блоков</td>
                                    </tr>
                                    @foreach ($priorBlocks as $pb)
                                        <tr>
                                            <td style="padding: 4px 0 4px 16px; color: #777;">{{ $pb['course_title'] ?? '' }} · Блок {{ $pb['block_number'] ?? '' }} · {{ $pb['user_name'] ?? '' }}</td>
                                            <td style="padding: 4px 0; text-align: right;">{{ $money($pb['teacher_amount'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td style="padding: 4px 0; color: #555;">Итого поздних оплат</td>
                                        <td style="padding: 4px 0; text-align: right; font-weight: bold;">{{ $money($b['prior_blocks_total'] ?? 0) }}</td>
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
                            Если есть вопросы по расчету — ответьте на это письмо.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
