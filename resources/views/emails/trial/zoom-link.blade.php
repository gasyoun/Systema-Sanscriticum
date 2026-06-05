<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Пробное занятие</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #38BDF8; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #38BDF8; line-height: 1;">🎟</span>
        </div>

        <h2 style="color: #0c6ea3; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $user->name ?? 'друг' }}! 🙏</h2>

        <p style="font-size: 17px; text-align: center;">Мы получили оплату пробного занятия курса <strong>«{{ $course->title }}»</strong>. Ждём вас на живом занятии!</p>

        <div style="background-color: #f0f9ff; border-left: 4px solid #38BDF8; padding: 20px; margin: 28px 0; border-radius: 0 4px 4px 0;">
            @if($startsAt)
                <p style="margin: 6px 0; font-size: 16px;"><strong>Когда:</strong> <span style="color: #0c6ea3;">{{ $startsAt->translatedFormat('d F Y, H:i') }} (МСК)</span></p>
            @endif
            @if($zoomLink)
                <p style="margin: 6px 0; font-size: 16px;"><strong>Подключение:</strong> по кнопке ниже</p>
            @endif
        </div>

        @if($zoomLink)
            <div style="text-align: center; margin: 32px 0;">
                <a href="{{ $zoomLink }}" style="background-color: #38BDF8; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Подключиться к Zoom</a>
            </div>
            <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">Если кнопка не работает, скопируйте ссылку: {{ $zoomLink }}</p>
        @else
            <p style="font-size: 16px; text-align: center; color: #8a3324;">Ссылку на подключение мы пришлём дополнительно — она появится в вашем личном кабинете.</p>
        @endif

        <p style="font-size: 15px; color: #7f8c8d; text-align: center; margin-top: 28px;">
            После занятия его запись появится в вашем личном кабинете в разделе «Пробные занятия».
        </p>

        <div style="text-align: center; margin-top: 24px;">
            <a href="{{ url('/login') }}" style="color: #0c6ea3; font-size: 14px;">Перейти в личный кабинет →</a>
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
