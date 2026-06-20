<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Напоминание об оплате</title>
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
                            @php
                                // Текст приходит уже с подставленными плейсхолдерами и \n.
                                // Экранируем; выделяем жирным главное — курс в «…» и «блок №N»;
                                // ссылки → <a>; переносы → <br>.
                                $safe = e($bodyText);
                                $safe = preg_replace('~«([^»]+)»~u', '«<strong>$1</strong>»', $safe);
                                $safe = preg_replace('~(блок\s*№\s*\d+)~iu', '<strong>$1</strong>', $safe);
                                $linked = preg_replace(
                                    '~(https?://[^\s<]+)~u',
                                    '<a href="$1" style="color: #E85C24; text-decoration: none; font-weight: bold;">$1</a>',
                                    $safe
                                );
                                $html = nl2br($linked);
                            @endphp
                            {!! $html !!}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0 40px 32px; color: #9aa0a6; font-size: 12px; line-height: 1.5;">
                            Это письмо отправлено по вашему обучению в Обществе ревнителей санскрита. Если оплата уже внесена — просто проигнорируйте его.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
