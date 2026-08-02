<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Вам выдан сертификат</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

                    <tr>
                        <td style="background-color: #E85C24; padding: 28px 30px; text-align: center;">
                            <h2 style="color: #ffffff; margin: 0; font-size: 21px; line-height: 1.3; font-weight: bold; letter-spacing: 0.3px;">
                                Общество ревнителей санскрита
                            </h2>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 40px; color: #1A1A1A; font-size: 16px; line-height: 1.6;">
                            <p style="margin: 0 0 16px;">Намасте, {{ $certificate->displayStudentName() }}!</p>
                            <p style="margin: 0 0 16px;">
                                Поздравляем — вам выдан сертификат
                                «<strong>{{ str_replace('|', ' ', $certificate->displayCourseTitle()) }}</strong>»
                                (№ {{ $certificate->number }}).
                            </p>
                            <p style="margin: 0 0 24px;">
                                Скачать его в PDF или JPG можно в личном кабинете, в разделе «Мои сертификаты».
                            </p>
                            <p style="margin: 0; text-align: center;">
                                <a href="{{ $dashboardUrl }}" style="display: inline-block; background-color: #E85C24; color: #ffffff; text-decoration: none; font-weight: bold; padding: 14px 28px; border-radius: 8px;">
                                    Открыть кабинет
                                </a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 40px 32px; color: #9aa0a6; font-size: 12px; line-height: 1.5;">
                            Это письмо отправлено по вашему обучению в Обществе ревнителей санскрита.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
