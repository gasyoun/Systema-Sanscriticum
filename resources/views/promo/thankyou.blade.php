<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Спасибо за заявку!</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🕉️</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    
    {{-- Подключаем стили Tailwind --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Montserrat', sans-serif; }
    </style>

    {{-- =========================================== --}}
    {{-- 1. ДИНАМИЧЕСКИЙ ЯНДЕКС (Берет ID из сессии) --}}
    {{-- =========================================== --}}
    @if(session('yandex_id'))
        <script type="text/javascript" >
           (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
           m[i].l=1*new Date();
           for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
           k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
           (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

           // Инициализируем счетчик с ID, который пришел из Контроллера
           ym(@intval(session('yandex_id')), "init", {
                clickmap:true,
                trackLinks:true,
                accurateTrackBounce:true,
                webvisor:true
           });
        </script>
        <noscript><div><img src="https://mc.yandex.ru/watch/{{ session('yandex_id') }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    @endif

    {{-- =========================================== --}}
    {{-- 2. ДИНАМИЧЕСКИЙ VK (Берет ID из сессии)     --}}
    {{-- =========================================== --}}
    @if(session('vk_id'))
        <script type="text/javascript">
            var _tmr = window._tmr || (window._tmr = []);
            _tmr.push({id: "{{ session('vk_id') }}", type: "pageView", start: (new Date()).getTime()});
            (function (d, w, id) {
                if (d.getElementById(id)) return;
                var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
                ts.src = "https://top-fwz1.mail.ru/js/code.js";
                var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
                if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
            })(document, window, "tmr-code");
        </script>
        <noscript><div><img src="https://top-fwz1.mail.ru/counter?id={{ session('vk_id') }};js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
    @endif
    
</head>
<body class="bg-gray-900 text-white flex items-center justify-center min-h-screen relative overflow-hidden">

    <div class="container mx-auto px-4 relative z-10 text-center max-w-2xl">

    @php
        // Мета каналов мессенджеров (label / gradient / shadow / svg) — единый
        // источник, чтобы дубликат-ветка и блок подписки не расходились.
        $channelMeta = \App\Support\ChannelMeta::all();
    @endphp

    @if(session('is_duplicate'))

        {{-- Состояние: повторная заявка с того же email --}}
        <div class="w-24 h-24 bg-yellow-500/20 rounded-full flex items-center justify-center mx-auto mb-8 shadow-[0_0_30px_-5px_rgba(234,179,8,0.4)]">
            <svg class="w-12 h-12 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
        </div>

        <h1 class="text-4xl md:text-5xl font-extrabold mb-6">Заявка уже зарегистрирована</h1>

        <p class="text-xl text-gray-300 mb-12 leading-relaxed">
            С адреса <span class="text-white font-semibold">{{ session('duplicate_email') }}</span> заявка на этот курс уже принята.<br>
            Наш менеджер обязательно свяжется с вами.
        </p>

        @include('promo.partials.curator-call-notice')

        <div class="mt-8"></div>

        @php
            $dupChannel = session('duplicate_channel');
            $dupLink = session('duplicate_deep_link');
            $dupMeta = ($dupChannel && $dupLink && isset($channelMeta[$dupChannel])) ? $channelMeta[$dupChannel] : null;
        @endphp

        @if($dupMeta)
            <a href="{{ $dupLink }}" target="_blank" rel="noopener noreferrer"
               class="group inline-flex items-center justify-center gap-3 px-10 py-5 text-lg font-bold text-white rounded-xl transition-all duration-300 hover:scale-105
                      bg-gradient-to-r {{ $dupMeta['gradient'] }} hover:brightness-110 {{ $dupMeta['shadow'] }}">
                <span class="inline-flex items-center justify-center transition-transform group-hover:-translate-y-0.5 group-hover:rotate-3">
                    {!! $dupMeta['svg'] !!}
                </span>
                Открыть {{ $dupMeta['label'] }}
            </a>
        @else
            <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer"
               class="group inline-flex items-center justify-center px-10 py-5 text-lg font-bold text-white rounded-xl transition-all duration-300 hover:scale-105
                      bg-gradient-to-r from-[#2AABEE] to-[#0088cc] hover:brightness-110
                      shadow-[0_0_25px_rgba(0,136,204,0.5)] hover:shadow-[0_0_40px_rgba(0,136,204,0.8)]">
                <svg class="w-7 h-7 mr-3 transition-transform group-hover:-translate-y-0.5 group-hover:rotate-3" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 11.944 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.697.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.628 4.476-1.636z"/>
                </svg>
                Написать в Telegram
            </a>
        @endif

        {{-- H3339: дубликат тоже видит полный блок «Подключить уведомления». --}}
        @if(session('status_connect_links'))
            @include('promo.partials.status-connect', ['links' => session('status_connect_links')])
        @endif

    @else

        {{-- Состояние: новая заявка принята --}}
        <div class="w-24 h-24 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-8 shadow-[0_0_30px_-5px_rgba(34,197,94,0.4)]">
            <svg class="w-12 h-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>

        <h1 class="text-4xl md:text-5xl font-extrabold mb-6">Ваша заявка принята!</h1>

        @php
            $hasAutoRedirect = (bool) session('redirect_url');
            $magnetTitle = session('magnet_title');
            $deepLinks = session('magnet_deep_links', []);
            $hasMagnet = ! empty($deepLinks);
            // H3339: подписка на статусы курса — кнопки каналов вместо
            // хардкод-кнопки Telegram.
            $statusLinks = session('status_connect_links', []);
            $hasStatus = ! empty($statusLinks);
        @endphp

        <p class="text-xl text-gray-300 mb-10 leading-relaxed">
            Спасибо! Мы уже получили ваши данные.<br>
            @if($hasMagnet && $magnetTitle)
                @if(count($deepLinks) === 1)
                    Нажмите кнопку ниже, чтобы получить
                    <strong class="text-white">«{{ $magnetTitle }}»</strong>:
                @else
                    Выберите удобный мессенджер, куда прислать
                    <strong class="text-white">«{{ $magnetTitle }}»</strong>:
                @endif
            @elseif($hasAutoRedirect)
                Сейчас вы будете перенаправлены в наш Telegram-канал…
            @elseif($hasStatus)
                Хотите получать статусы этого курса в мессенджер? Подключите уведомления ниже.
            @else
                Чтобы ускорить процесс, напишите нам в Telegram прямо сейчас.
            @endif
        </p>

        @if($hasMagnet)
            {{-- Обычно одна кнопка — канал, который студент указал в форме (см. LeadFlashBuilder).
                 Несколько появляются только в fallback, когда его канал не настроен. --}}
            <div class="flex flex-wrap justify-center gap-3 mb-2">
                @foreach($deepLinks as $channel => $url)
                    @php $meta = $channelMeta[$channel] ?? null; @endphp
                    @if($meta)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                           @if(session('yandex_id'))
                               onclick="ym(@intval(session('yandex_id')), 'reachGoal', 'magnet_{{ $channel }}_click'); return true;"
                           @endif
                           class="group inline-flex items-center gap-2 px-5 py-2.5 text-sm font-bold rounded-lg text-white
                                  bg-gradient-to-r {{ $meta['gradient'] }} hover:brightness-110
                                  transition-all duration-200 hover:scale-105 {{ $meta['shadow'] }}">
                            {!! $meta['svg'] !!}
                            <span>{{ $meta['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>

            {{-- VK по архитектуре требует первого сообщения от юзера, чтобы бот мог ответить.
                 Показываем подсказку, только если VK среди настроенных каналов. --}}
            @if(isset($deepLinks['vk']))
                <p class="mt-4 text-xs text-gray-500 max-w-md mx-auto leading-relaxed">
                    Если выбрали <strong class="text-gray-400">ВКонтакте</strong> — после открытия чата
                    нажмите кнопку <strong class="text-gray-400">«Начать»</strong> или напишите боту
                    любое сообщение. Telegram и Max откроют файл автоматически.
                </p>
            @endif

            @if($hasAutoRedirect)
                <p class="mt-4 text-xs text-gray-500">
                    Сейчас откроем выбранный мессенджер автоматически. Если перенаправление не сработало — нажмите кнопку выше.
                </p>
            @endif
        @elseif($hasStatus)
            {{-- H3339: без магнита кнопки каналов рендерит блок подписки ниже. --}}
        @else
            @php $tgUrl = session('redirect_url') ?: 'https://t.me/rusamskrtam'; @endphp
            <a href="{{ $tgUrl }}" target="_blank" rel="noopener noreferrer"
               @if(session('yandex_id'))
                   onclick="ym(@intval(session('yandex_id')), 'reachGoal', 'telegram_click'); return true;"
               @endif
               class="group inline-flex items-center justify-center px-6 py-3 text-base font-bold text-white rounded-xl transition-all duration-300 hover:scale-105
                      bg-gradient-to-r from-[#2AABEE] to-[#0088cc] hover:brightness-110
                      shadow-[0_0_20px_rgba(0,136,204,0.4)] hover:shadow-[0_0_30px_rgba(0,136,204,0.6)]">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 11.944 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.697.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.628 4.476-1.636z"/>
                </svg>
                @if($hasAutoRedirect)
                    Перейти в Telegram сейчас
                @else
                    Написать в Telegram
                @endif
            </a>
            @if($hasAutoRedirect)
                <p class="mt-4 text-xs text-gray-500">
                    Если перенаправление не сработало — нажмите кнопку выше.
                </p>
            @endif
        @endif

        {{-- H3339: блок «Подключить уведомления» — всем заявившимся на лендинг с status_block. --}}
        @if($hasStatus)
            @include('promo.partials.status-connect', ['links' => $statusLinks])
        @endif

        @include('promo.partials.curator-call-notice')

    @endif

        <div class="mt-10">
            <a href="/" class="text-gray-500 hover:text-white text-sm transition-colors border-b border-transparent hover:border-gray-700 pb-1">
                Вернуться на главную
            </a>
        </div>

    </div>

    {{-- СКРИПТ ОТПРАВКИ ЦЕЛЕЙ (Срабатывает при загрузке) --}}
    <script>
    document.addEventListener("DOMContentLoaded", function () {

        // ====================================================
        // Конфигурация редиректа (если URL передан из контроллера)
        // ====================================================
        @if(session('redirect_url'))
            var redirectUrl       = @json(session('redirect_url'));
            var redirectFired     = false;
            var MIN_DISPLAY_MS    = 4000; // минимум, сколько пользователь видит страницу "Спасибо"
            var FALLBACK_MS       = 6000; // страховка: если Метрика не вызовет callback вообще

            // Страховочный таймер на случай, если callback Метрики не выстрелит
            // (AdBlock, сбой tag.js, отвалилась сеть к Метрике).
            // Должен быть БОЛЬШЕ, чем MIN_DISPLAY_MS, иначе превратится в основной путь.
            var fallbackTimeout = setTimeout(function () {
                fireRedirect();
            }, FALLBACK_MS);

            function fireRedirect() {
                if (redirectFired) return;
                redirectFired = true;
                clearTimeout(fallbackTimeout);
                window.location.href = redirectUrl;
            }

            // Запускает редирект НЕ РАНЬШЕ, чем через MIN_DISPLAY_MS от загрузки страницы.
            // Если цель отстрелялась раньше — ждём остаток времени. Если позже — редиректим сразу.
            var pageLoadedAt = Date.now();
            function fireRedirectWithMinDelay() {
                var elapsed = Date.now() - pageLoadedAt;
                var remaining = Math.max(0, MIN_DISPLAY_MS - elapsed);
                setTimeout(fireRedirect, remaining);
            }
        @endif

        // ====================================================
        // 1. Яндекс.Метрика
        // ====================================================
        @if(session('yandex_id'))
            if (typeof ym !== 'undefined') {
                ym({{ session('yandex_id') }}, 'reachGoal', '{{ session('conversion_event', 'lead') }}'
                    @if(session('redirect_url'))
                        , {}, fireRedirectWithMinDelay
                    @endif
                );
                console.log('Yandex Goal sent: {{ session('conversion_event', 'lead') }} for ID: {{ session('yandex_id') }}');
            }
        @endif

        // ====================================================
        // 2. VK Pixel
        // ====================================================
        @if(session('vk_id'))
            var _tmr = window._tmr || (window._tmr = []);
            _tmr.push({ type: 'reachGoal', id: "{{ session('vk_id') }}", goal: 'lead_form' });
            console.log('VK Goal sent: lead_form for ID: {{ session('vk_id') }}');
        @endif

        // ====================================================
        // 3. Если редирект задан, но Яндекс не подключён —
        // запускаем редирект с минимальной задержкой
        // ====================================================
        @if(session('redirect_url') && !session('yandex_id'))
            fireRedirectWithMinDelay();
        @endif
    });
</script>
</body>
</html>