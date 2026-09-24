{{-- H5451: standalone-страница живого чата для iframe-эмбеда на samskrtam.ru
     (<iframe src="https://samskrte.ru/chat/embed?page=<URL товара>">).
     Стоит вне blade-лэйаутов (как course-interest/embed): собственный <html>
     без навигации и хрома кабинета. Внутри — переиспользованный
     support-chat-widget (частичный) в эмбед-режиме: пузырь-кнопка и кнопка
     «Свернуть» спрятаны (открывает/закрывает iframe кнопка на WP-стороне),
     панель растянута на весь iframe и раскрыта сразу.

     `?page=` (URL товара samskrtam.ru, уже очищен в контроллере) выставляется
     в window.SCW_EMBED_PAGE ДО включения частичного: виджет читает его в
     контекстном приветствии («Вопрос по этому товару…») и шлет в payload.page
     / presence-beacon — куратор видит в Helpdesk URL магазина, а не адрес
     iframe. В кабинете SCW_EMBED_PAGE не задается — поведение виджета там
     не меняется. --}}
<!DOCTYPE html>
<html lang="ru" class="scw-embed">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Чат с поддержкой</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; background: transparent; }

        /* Эмбед-режим: без пузыря и «Свернуть» — управляет iframe WP-кнопка. */
        .scw-embed #scw-toggle,
        .scw-embed #scw-close { display: none !important; }
        .scw-embed .scw {
            position: static; right: auto; bottom: auto; width: 100%; height: 100%;
        }
        .scw-embed .scw-panel {
            position: fixed; inset: 0; bottom: 0; width: 100%; height: 100%;
            max-height: none; max-width: none; border-radius: 0; border: none;
            animation: none; box-shadow: none;
        }
        @media (max-width: 480px) { .scw-embed .scw { right: auto; bottom: auto; } }
    </style>
</head>
<body>
    <script>
        // Страница магазина (?page=), уже очищенная сервером; '' → виджет
        // живет телеметрией адреса iframe, как без параметра.
        window.SCW_EMBED_PAGE = @json($embedPage);
    </script>

    @include('partials.support-chat-widget')

    <script>
        // Раскрыть панель сразу: iframe и есть открытый чат. Клик идет в
        // штатный слушатель виджета (openPanel) — своей логики раскрытия нет.
        (function () {
            var t = document.getElementById('scw-toggle');
            if (t) { t.click(); }
        })();
    </script>
</body>
</html>
