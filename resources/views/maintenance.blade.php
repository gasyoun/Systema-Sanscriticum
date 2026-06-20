<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Личный кабинет на техобслуживании</title>
</head>
<body style="font-family: 'Georgia', serif; background-color: #fcf9f2; color: #3e3a37; line-height: 1.6; margin: 0; padding: 40px 20px; min-height: 100vh; box-sizing: border-box; display: flex; align-items: center; justify-content: center;">

    <div style="max-width: 560px; width: 100%; background-color: #ffffff; padding: 48px 32px; border-top: 6px solid #E85C24; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); text-align: center;">

        <div style="font-size: 64px; color: #E85C24; line-height: 1; margin-bottom: 16px;">ॐ</div>

        <h1 style="color: #c0531f; font-size: 26px; margin: 0 0 16px; font-weight: normal;">
            Личный кабинет на техобслуживании
        </h1>

        <div style="font-size: 17px; color: #5a554f;">
            @if(!empty($message))
                {!! nl2br(e($message)) !!}
            @else
                Скоро вернёмся 🙏 Идут технические работы — кабинет ненадолго недоступен.
                Спасибо за терпение.
            @endif
        </div>

        <hr style="border: none; border-top: 1px solid #f0e6d2; margin: 36px 0 20px;">

        <p style="margin: 0; font-size: 14px; color: #95a5a6;">
            Общество ревнителей санскрита
        </p>
    </div>

</body>
</html>
