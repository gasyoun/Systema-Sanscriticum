<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Новая домашняя работа</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #d35400; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #d35400; line-height: 1;">📝</span>
        </div>

        <h2 style="color: #8a3324; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Новая работа на проверку</h2>

        <p style="font-size: 17px;">Студент <strong>{{ $submission->user->name ?? 'Студент' }}</strong> отправил домашнюю работу.</p>

        <div style="background-color: #fff8f0; border-left: 4px solid #d35400; padding: 16px 20px; margin: 24px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 6px 0; font-size: 15px;"><strong>Курс:</strong> {{ $submission->course->title ?? '—' }}</p>
            <p style="margin: 6px 0; font-size: 15px;"><strong>Урок:</strong> {{ $submission->lesson->title ?? '—' }}</p>
        </div>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $reviewUrl }}" style="background-color: #d35400; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Проверить работу</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
