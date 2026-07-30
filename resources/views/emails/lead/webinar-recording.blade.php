<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Запись вебинара «{{ $landing->webinar_label ?: 'Вебинар' }}»</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #E85C24; line-height: 1;">▶️</span>
        </div>

        <h2 style="color: #c0531f; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $lead->name ?: 'друг' }}!</h2>

        <p style="font-size: 17px; text-align: center;">
            Запись вебинара <strong>«{{ $landing->webinar_label ?: 'вебинар' }}»</strong> готова — можно посмотреть в удобное время.
        </p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $landing->webinar_recording_url }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Смотреть запись</a>
        </div>
        <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $landing->webinar_recording_url }}</p>

        <p style="font-size: 15px; color: #7f8c8d; text-align: center; margin-top: 28px;">
            Сохраните это письмо, чтобы вернуться к записи позже.
        </p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
