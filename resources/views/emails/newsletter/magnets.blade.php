<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ваши бонусы и вход в кабинет</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px;">

    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 40px 30px; border-top: 6px solid #E85C24; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <div style="text-align: center; margin-bottom: 25px;">
            <span style="font-size: 56px; color: #E85C24; line-height: 1;">🎁</span>
        </div>

        <h2 style="color: #c0531f; text-align: center; font-size: 24px; margin-top: 0; font-weight: normal;">Намасте, {{ $userName ?: 'друг' }}! 🙏</h2>

        <p style="font-size: 17px; text-align: center;">
            Спасибо за подписку! Мы завели для вас личный кабинет — войдите по кнопке ниже,
            там на «полке подписчика» ждут бесплатные материалы.
        </p>

        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $magicUrl }}" style="background-color: #E85C24; color: #ffffff; padding: 14px 35px; text-decoration: none; border-radius: 30px; font-weight: bold; font-size: 15px; display: inline-block; text-transform: uppercase; letter-spacing: 1px;">Войти в кабинет</a>
        </div>
        <p style="font-size: 13px; color: #95a5a6; text-align: center; word-break: break-all;">
            Ссылка действует одноразово и ограниченное время. Если кнопка не работает, скопируйте: {{ $magicUrl }}
        </p>

        @if ($magnets->isNotEmpty())
            <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">
            <h3 style="color: #c0531f; font-size: 19px; font-weight: normal; text-align: center;">Ваши материалы</h3>
            <ul style="list-style: none; padding: 0; margin: 20px 0;">
                @foreach ($magnets as $magnet)
                    @php $url = $magnet->publicUrl(); @endphp
                    <li style="margin: 0 0 14px; padding: 14px 16px; background: #fcf9f2; border-radius: 8px;">
                        <strong style="font-size: 16px;">{{ $magnet->title }}</strong>
                        @if ($magnet->description)
                            <div style="font-size: 14px; color: #7f8c8d; margin-top: 4px;">{{ $magnet->description }}</div>
                        @endif
                        @if ($url)
                            <div style="margin-top: 8px;">
                                <a href="{{ $url }}" style="color: #E85C24; font-weight: bold; text-decoration: none;">Скачать / открыть →</a>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0;">

        <p style="margin-top: 0; font-size: 14px; color: #95a5a6; text-align: center;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
