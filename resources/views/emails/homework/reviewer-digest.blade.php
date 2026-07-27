<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Домашки за неделю</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #d35400; line-height: 1;">📊</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Домашки за неделю</h2>

        <p style="font-size: 17px;">Курс <strong>«{{ $course->title ?? '—' }}»</strong>. Работы этих групп проверяет {{ $summary['reviewers'] }} — письма по каждой работе идут ему, вам приходит эта сводка.</p>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 16px 20px; margin: 24px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 6px 0; font-size: 15px;"><strong>Сдано за неделю:</strong> {{ $summary['submitted'] }}</p>
            <p style="margin: 6px 0; font-size: 15px;"><strong>Проверено:</strong> {{ $summary['reviewed'] }}</p>
            <p style="margin: 6px 0; font-size: 15px;"><strong>Ждёт проверки:</strong> {{ $summary['waiting'] }}</p>
        </div>

        @if (! empty($summary['waiting_rows']))
            <p style="font-size: 15px; margin-bottom: 8px;"><strong>Дольше всех ждут:</strong></p>
            <ul style="font-size: 15px; padding-left: 20px; margin-top: 0;">
                @foreach ($summary['waiting_rows'] as $row)
                    <li style="margin: 4px 0;">{{ $row['student'] }} — {{ $row['lesson'] }} <span style="color: #8a7f76;">({{ $row['waiting_since'] }})</span></li>
                @endforeach
            </ul>
        @endif

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $queueUrl }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Открыть очередь</a>
        </div>

        <p style="font-size: 13px; color: #8a7f76; text-align: center; margin-bottom: 0;">Сводка приходит раз в неделю, пока у групп курса есть назначенный проверяющий.</p>

    </div>

</body>
</html>
