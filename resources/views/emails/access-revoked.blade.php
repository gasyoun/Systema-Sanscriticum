@php
    $loginUrl = url('/login');
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Доступ временно закрыт</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #1f2937; max-width: 560px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #111827;">🔒 Доступ временно закрыт</h2>

    <p>Намасте, {{ $student->name }}!</p>

    <p>
        Доступ к курсу <strong>«{{ $course->title }}»</strong> приостановлен:
        обещанная оплата не поступила в срок. Это техническая мера, не оценка вас как ученика.
        После оплаты доступ откроется снова.
    </p>

    @if (! empty($reasonNote))
        <p style="background: #fef3c7; padding: 12px; border-radius: 6px;">
            <em>Комментарий менеджера:</em><br>
            {{ $reasonNote }}
        </p>
    @endif

    <p>
        Если вы уже оплатили — напишите нам, мы быстро откроем доступ вручную.
        Дублирование уведомления приходит и в Telegram, и в VK, и в Max — на тот случай,
        если какой-то канал у вас сейчас недоступен.
    </p>

    <p>
        <a href="{{ $loginUrl }}" style="display: inline-block; background: #4f46e5; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none;">
            Перейти в личный кабинет
        </a>
    </p>

    <p style="color: #6b7280; font-size: 13px; margin-top: 32px;">
        Это автоматическое письмо Общества ревнителей санскрита.
    </p>
</body>
</html>
