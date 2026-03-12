<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page?->title }}</title>
    
    {{-- === SEO И OPEN GRAPH === --}}
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $page?->title }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($page->description ?? 'Запишитесь на курс прямо сейчас!'), 150) }}"> 

    @php
        // 1. Заглушка по умолчанию
        $ogImage = asset('images/logo.png'); 

        // 2. Если жестко задана обложка в настройках страницы
        if (!empty($page->image_path)) {
            $ogImage = url(Storage::url($page->image_path));
        }
        // 3. Иначе ищем в блоках (Приоритет: Преподаватель -> Герой)
        elseif (!empty($page->content) && is_array($page->content)) {
            $heroImg = null;
            $teacherImg = null;

            // Пробегаем по всем блокам и запоминаем найденные картинки
            foreach ($page->content as $block) {
                if ($block['type'] === 'hero_block' && !empty($block['data']['image'])) {
                    $heroImg = url(Storage::url($block['data']['image']));
                }
                if ($block['type'] === 'teacher_block' && !empty($block['data']['image'])) {
                    $teacherImg = url(Storage::url($block['data']['image']));
                }
            }

            // ЛОГИКА ПРИОРИТЕТА: Если нашли препода - берем его. Если нет - берем героя.
            if ($teacherImg) {
                $ogImage = $teacherImg;
            } elseif ($heroImg) {
                $ogImage = $heroImg;
            }
        }
    @endphp

    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    {{-- === КОНЕЦ SEO === --}}

    {{-- FONTS & STYLES --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { font-family: 'Montserrat', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>

    {{-- ANALYTICS --}}
    @if($page?->yandex_metrika_id)
    <script type="text/javascript" >
       (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
       m[i].l=1*new Date();
       for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
       k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
       (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

       ym({{ $page?->yandex_metrika_id }}, "init", {
           clickmap:true,
           trackLinks:true,
           accurateTrackBounce:true,
           webvisor:true
       });
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/{{ $page->yandex_metrika_id }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    @endif

    @if($page?->vk_pixel_id)
    <script type="text/javascript">
      var _tmr = window._tmr || (window._tmr = []);
      _tmr.push({id: "{{ $page->vk_pixel_id }}", type: "pageView", start: (new Date()).getTime()});
      (function (d, w, id) {
        if (d.getElementById(id)) return;
        var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
        ts.src = "https://top-fwz1.mail.ru/js/code.js";
        var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
        if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
      })(document, window, "tmr-code");
    </script>
    <noscript><div><img src="https://top-fwz1.mail.ru/counter?id={{ $page->vk_pixel_id }};js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
    @endif
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden"
      x-data="{ 
          openConsent: false, 
          targetUrl: '', 
          agreed: false,
          openDoc: false, docTitle: '', docUrl: '',

          askConsent(url) {
              this.targetUrl = url;
              this.agreed = false;
              this.openConsent = true;
          },
          proceed() {
              if (this.agreed) {
                  // Отправка целей
                  @if($page?->yandex_metrika_id)
                      if (typeof ym !== 'undefined') ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'begin_checkout');
                  @endif
                  @if($page?->vk_pixel_id)
                      if (typeof _tmr !== 'undefined') _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'begin_checkout' });
                  @endif
                  setTimeout(() => { window.location.href = this.targetUrl; }, 300);
              }
          },
          viewDocument(title, url) {
              this.docTitle = title;
              this.docUrl = url;
              this.openDoc = true;
          }
      }">

    {{-- СЮДА БУДУТ ВСТАВЛЯТЬСЯ НАШИ БЛОКИ --}}
    @yield('content')

    {{-- ФУТЕР --}}
    <footer class="bg-gray-900 text-gray-400 py-10 border-t border-gray-800 text-sm">
        <div class="container mx-auto px-4 text-center">
            <p class="mb-4">&copy; {{ date('Y') }} Все права защищены.</p>
            <button @click="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-gray-500 hover:text-white underline transition-colors duration-300">
                Политика конфиденциальности
            </button>
        </div>
    </footer>

    {{-- МОДАЛКИ (PDF и Согласие) --}}
    @include('promo.partials.modals')

    {{-- СКРИПТЫ (Таймер и Скролл) --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 60 секунд
            setTimeout(function() {
                @if($page?->yandex_metrika_id)
                if (typeof ym !== 'undefined') ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'time_60s');
                @endif
                @if($page?->vk_pixel_id)
                if (typeof _tmr !== 'undefined') _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'time_60s'});
                @endif
            }, 60000); 

            // Скролл к форме (ищем ID order-form-anchor)
            let formBlock = document.getElementById('order-form-anchor');
            if (formBlock) {
                let observer = new IntersectionObserver(function(entries) {
                    if (entries[0].isIntersecting) {
                        @if($page?->yandex_metrika_id)
                        if (typeof ym !== 'undefined') ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'scroll_bottom');
                        @endif
                        @if($page?->vk_pixel_id)
                        if (typeof _tmr !== 'undefined') _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'scroll_bottom'});
                        @endif
                        observer.disconnect(); 
                    }
                });
                observer.observe(formBlock);
            }
        });
    </script>
</body>
</html>