<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Результат проверки домашней работы</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid {{ $accepted ? '#27894a' : '#d35400' }}; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 20px;">
            <span style="font-size: 56px; line-height: 1;">{{ $accepted ? '✅' : '✍️' }}</span>
        </div>

        <h2 style="color: {{ $accepted ? '#27894a' : '#8a3324' }}; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">
            {{ $accepted ? 'Работа принята!' : 'Нужно доработать' }}
        </h2>

        <p style="font-size: 17px; text-align: center;">
            Преподаватель проверил вашу домашнюю работу по уроку
            <strong>«{{ $submission->lesson->title ?? '—' }}»</strong>
            (курс «{{ $submission->course->title ?? '—' }}»).
        </p>

        @if(!empty($review->body))
        <div style="background-color: #faf7f0; border-left: 4px solid {{ $accepted ? '#27894a' : '#d35400' }}; padding: 16px 20px; margin: 24px 0; border-radius: 0 4px 4px 0;">
            <p style="margin: 0 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #95a5a6;">Комментарий преподавателя</p>
            <p style="margin: 0; font-size: 16px; white-space: pre-line;">{{ $review->body }}</p>
        </div>
        @endif

        @unless($accepted)
        <p style="font-size: 16px;">Внесите правки и отправьте работу снова в личном кабинете.</p>
        @endunless

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $lessonUrl }}" style="background-color: {{ $accepted ? '#27894a' : '#d35400' }}; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Открыть урок</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
