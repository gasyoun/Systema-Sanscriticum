<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Открыт урок: {{ $lesson->title }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden;">

                    <tr>
                        <td style="background-color: #E85C24; padding: 28px 30px; text-align: center;">
                            <h2 style="color: #ffffff; margin: 0; font-size: 21px; line-height: 1.3; font-weight: bold;">
                                Общество ревнителей санскрита
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px; color: #1A1A1A; font-size: 16px; line-height: 1.6;">
                            <p style="margin: 0 0 16px;">Намаскарам, {{ $user->name }}! 🙏</p>

                            <p style="margin: 0 0 16px;">
                                В вашем бесплатном курсе <b>«{{ $courseTitle }}»</b> открыт следующий урок:
                            </p>

                            <p style="margin: 0 0 24px; font-size: 19px; font-weight: bold; color: #E85C24;">
                                📖 {{ $lesson->title }}
                            </p>

                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 10px 0 26px;">
                                        <a href="{{ $lessonUrl }}"
                                           style="display: inline-block; padding: 14px 34px; background-color: #E85C24; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 12px; font-size: 16px;">
                                            Приступить к уроку
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 16px; font-size: 14px; color: #475569;">
                                Уроки открываются каждые три дня — размеренный ритм, чтобы материал
                                успевал укладываться. Домашку присылайте прямо с страницы урока,
                                вопросы — куратору в Telegram.
                            </p>

                            <p style="margin: 0; font-size: 14px; color: #475569;">
                                Сдали итоговый тест — ждёт скидка 50% на первый блок грамматики
                                и живое занятие с Ушей Санкой. 🕉
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 20px 40px 28px; border-top: 1px solid #f1f5f9;">
                            <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                                Если кнопка не работает, откройте урок по ссылке:
                                <a href="{{ $lessonUrl }}" style="color: #E85C24;">{{ $lessonUrl }}</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
