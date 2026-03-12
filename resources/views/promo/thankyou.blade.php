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
           ym({{ session('yandex_id') }}, "init", {
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
        
        <div class="w-24 h-24 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-8 shadow-[0_0_30px_-5px_rgba(34,197,94,0.4)]">
            <svg class="w-12 h-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>

        <h1 class="text-4xl md:text-5xl font-extrabold mb-6">Ваша заявка принята!</h1>
        
        <p class="text-xl text-gray-300 mb-12 leading-relaxed">
            Спасибо! Мы уже получили ваши данные.<br>
            Чтобы ускорить процесс, напишите нам в Telegram прямо сейчас.
        </p>

        {{-- КНОПКА TELEGRAM --}}
        {{-- Используем проверку: если есть yandex_id, добавляем событие клика --}}
        <a href="https://t.me/rusamskrtam" target="_blank" 
           @if(session('yandex_id'))
               onclick="ym({{ session('yandex_id') }}, 'reachGoal', 'telegram_click'); return true;"
           @endif
           class="group inline-flex items-center justify-center px-10 py-5 text-lg font-bold text-white rounded-xl transition-all duration-300 hover:scale-105
                  bg-gradient-to-r from-[#2AABEE] to-[#0088cc] hover:brightness-110
                  shadow-[0_0_25px_rgba(0,136,204,0.5)] hover:shadow-[0_0_40px_rgba(0,136,204,0.8)]">
            
            <svg class="w-7 h-7 mr-3 transition-transform group-hover:-translate-y-0.5 group-hover:rotate-3" fill="currentColor" viewBox="0 0 24 24">
                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 11.944 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.697.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.628 4.476-1.636z"/>
            </svg>
            Написать в Telegram
        </a>

        <div class="mt-10">
            <a href="/" class="text-gray-500 hover:text-white text-sm transition-colors border-b border-transparent hover:border-gray-700 pb-1">
                Вернуться на главную
            </a>
        </div>

    </div>

    {{-- СКРИПТ ОТПРАВКИ ЦЕЛЕЙ (Срабатывает при загрузке) --}}
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            
            // 1. Отправка в Яндекс (если ID передан)
            @if(session('yandex_id'))
                if (typeof ym !== 'undefined') {
                    // Цель: lead (или та, что пришла из контроллера)
                    ym({{ session('yandex_id') }}, 'reachGoal', '{{ session('conversion_event', 'lead') }}');
                    console.log('Yandex Goal sent: {{ session('conversion_event', 'lead') }} for ID: {{ session('yandex_id') }}');
                }
            @endif

            // 2. Отправка в VK (если ID передан)
            @if(session('vk_id'))
                var _tmr = window._tmr || (window._tmr = []);
                _tmr.push({ type: 'reachGoal', id: "{{ session('vk_id') }}", goal: 'lead_form'});
                console.log('VK Goal sent: lead_form for ID: {{ session('vk_id') }}');
            @endif

        });
    </script>
</body>
</html>