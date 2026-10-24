<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Оплата открыта: {{ $courseTitle }}</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="font-size: 17px;">
            {!! $bodyHtml !!}
        </div>

        <p style="text-align: center; margin: 32px 0 8px;">
            <a href="{{ $url }}" style="display: inline-block; background-color: #E85C24; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-family: Arial, sans-serif; font-size: 16px;">Записаться и оплатить</a>
        </p>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

    @include('emails.partials.unsubscribe-footer')
</body>
</html>
