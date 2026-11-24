<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Спроси Сократа: две недели теста</title>
</head>
{{-- Письмо-приглашение в тест ИИ-помощника занятия.
     Вёрстка таблицами и инлайн-стилями: <style> и flexbox режут Gmail и Outlook.
     Формы в письме нет сознательно — согласие даётся на подписанной странице
     (LessonAiConsentController), иначе автопросмотр ссылок давал бы согласие
     без человека. --}}
<body style="margin:0; padding:0; background-color:#fcf9f2; font-family: Georgia, 'Times New Roman', serif; color:#3e3a37;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Две недели бесплатного теста, потом 2000 ₽ в месяц. Доступ включается по вашему согласию.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fcf9f2;">
        <tr>
            <td align="center" style="padding:40px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border-top:6px solid #d35400; border-radius:8px;">

                    <tr>
                        <td style="padding:32px 32px 0 32px; text-align:center; font-size:56px; line-height:1; color:#d35400;">&#2384;</td>
                    </tr>

                    <tr>
                        <td style="padding:8px 32px 0 32px;">
                            <h1 style="margin:0 0 20px 0; font-size:25px; font-weight:normal; color:#8a3324; text-align:center;">
                                Намасте, {{ $user->greetingName('друг') }}!
                            </h1>

                            <p style="margin:0 0 14px 0; font-size:17px; line-height:1.6;">
                                В уроках кабинета появился помощник занятия — вкладка
                                <strong>«Спроси Сократа»</strong> справа от записи. Он не пересказывает вместо вас
                                и не заменяет преподавателя, а работает между занятиями: возвращает к нужной минуте,
                                готовит к домашнему, разбирает термин, который не дался.
                            </p>

                            <p style="margin:0 0 14px 0; font-size:17px; line-height:1.6;">
                                <strong>Это тест на две недели: с {{ $startsOn }} по {{ $endsOn }}.</strong>
                                Он бесплатный. После теста ничего не спишется автоматически: подписку вы оформляете
                                сами, если решите, что помощник вам нужен. После официального релиза он будет стоить
                                <strong>{{ $price }} ₽ в месяц</strong>; что входит в тариф и какой в нем лимит
                                запросов, опубликуем к релизу — чтобы вы решали по цифрам, а не по обещаниям.
                            </p>

                            <p style="margin:0 0 8px 0; font-size:17px; line-height:1.6;">
                                <strong>Почему пишем именно вам.</strong> Вы регулярно занимаетесь и смотрите записи,
                                а помощник отвечает по записи занятия. Значит, вам есть с чем сверить его ответ:
                                вы помните, о чем шла речь, и сразу заметите, если пересказ придумал тему, которой
                                на занятии не было. Тому, кто только начинает, это не под силу — он еще не знает,
                                как выглядит наше занятие, и молча примет ошибку за норму.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:6px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fffaf3; border:1px solid #f0e6d2; border-radius:6px;">
                                <tr>
                                    <td style="padding:16px 18px; font-size:16px; line-height:1.55;">
                                        <p style="margin:0 0 8px 0;"><strong>Что он умеет:</strong></p>
                                        <p style="margin:0 0 4px 0;">&#8212; «Пересказ занятия»: оглавление с таймкодами, чтобы вернуться к нужной минуте;</p>
                                        <p style="margin:0 0 4px 0;">&#8212; «Вопросы для самопроверки»: ответы спрятаны, проверяйте себя честно;</p>
                                        <p style="margin:0 0 4px 0;">&#8212; «Конспект из заметок»: черновые пометки превращаются в аккуратный конспект;</p>
                                        <p style="margin:0;">&#8212; «Объяснить выделенное» и «Вопрос по занятию»: про то, что осталось непонятным (например, что такое висарга).</p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:14px 0 0 0; font-size:15px; line-height:1.6; color:#6b615a;">
                                На день дается {{ $dailyRequests }} запросов; готовые пересказы и неудачные запросы
                                лимит не тратят. Ночью, вне 09:30&#8211;20:30 МСК, новые запросы не принимаются —
                                готовые пересказы открываются. Ответ помощника — подсказка, а не слова преподавателя:
                                спорное сверяйте с записью.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e8ddc8; border-radius:8px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 10px 0; font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#95a5a6;">
                                            Согласие на участие
                                        </p>
                                        <p style="margin:0 0 12px 0; font-size:15px; line-height:1.6; color:#3e3a37;">
                                            Чтобы мы разбирали ваши вопросы к помощнику и сообщения об ошибках и по ним
                                            правили ответы, нужно ваше согласие на обработку персональных данных: имя,
                                            адрес почты, текст вопросов помощнику, текст заметок к занятиям и сообщения
                                            об ошибках. Согласие добровольное и с деньгами не связано: отказ не влияет
                                            ни на доступ к кабинету и урокам, ни на цену.
                                        </p>
                                        <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#6b615a;">
                                            <a href="https://samskrte.ru/docs/privacy.pdf" style="color:#d35400;">Политика конфиденциальности</a>
                                            &#183;
                                            <a href="https://samskrte.ru/docs/soglasie-pd.pdf" style="color:#d35400;">Согласие на обработку данных</a>
                                        </p>

                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px 0;">
                                            <tr>
                                                <td align="center" bgcolor="#d35400" style="border-radius:6px;">
                                                    <a href="{{ $consentUrl }}" style="display:inline-block; padding:14px 28px; font-family: Georgia, serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none;">
                                                        Да, согласен(на) &#8212; участвую
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin:0 0 10px 0; font-size:15px;">
                                            <a href="{{ $declineUrl }}" style="color:#8a3324; text-decoration:underline;">Нет, спасибо</a>
                                            &#8212; помощник останется доступен, просто разбирать ваши вопросы мы не будем.
                                        </p>

                                        <p style="margin:0; font-size:14px; line-height:1.6; color:#95a5a6;">
                                            Молчание мы согласием не считаем: без вашего ответа ничего не меняется.
                                            Отозвать согласие можно в любой момент — ответом на это письмо или через куратора.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:22px 32px 0 32px;">
                            <p style="margin:0 0 14px 0; font-size:16px; line-height:1.6;">
                                Через две недели закроем тест, соберем выводы и отдельно напишем, что решили.
                                Если помощник ошибся, придумал или ответил не по занятию — под уроком есть кнопка
                                «Куратору»: напишите, какой урок, что спросили и в чем ошибка. Так мы поправим его быстрее.
                            </p>
                            <p style="margin:0 0 6px 0; font-size:16px; line-height:1.6;">Спасибо, что помогаете.</p>
                            <p style="margin:0 0 28px 0; font-size:16px; line-height:1.6;">{{ $senderName }}</p>

                            <p style="margin:0 0 26px 0; font-size:12px; line-height:1.5; color:#b0a79e; word-break:break-all;">
                                Если кнопка не открывается, скопируйте ссылку:
                                {{ $consentUrl }}<br>
                                Отказ: {{ $declineUrl }}
                            </p>
                        </td>
                    </tr>

                </table>

                @include('emails.partials.unsubscribe-footer')

            </td>
        </tr>
    </table>
</body>
</html>
