<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $landing->webinar_label ?: 'Вебинар' }}</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #E85C24; line-height: 1;">🎓</span>
        </div>

        <h2 style="color: #c0531f; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $lead->name ?: 'друг' }}!</h2>

        <p style="font-size: 17px; text-align: center;">
            Спасибо за заявку! Вы записаны на <strong>«{{ $landing->webinar_label ?: 'вебинар' }}»</strong>.
        </p>

        <div style="background-color: #fdf1ea; border-left: 4px solid #E85C24; padding: 20px; margin: 28px 0; border-radius: 0 4px 4px 0;">
            @if($landing->webinar_date)
                <p style="margin: 6px 0; font-size: 16px;"><strong>Когда:</strong> <span style="color: #c0531f;">{{ $landing->webinar_date->translatedFormat('d F Y, H:i') }} (МСК)</span></p>
            @endif
            <p style="margin: 6px 0; font-size: 16px;"><strong>Подключение:</strong> по кнопке ниже</p>
        </div>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $landing->webinar_url }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Перейти на вебинар</a>
        </div>
        <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $landing->webinar_url }}</p>

        <p style="font-size: 15px; color: #7f8c8d; text-align: center; margin-top: 28px;">
            Сохраните это письмо — ссылка будет действовать в день вебинара.
        </p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
