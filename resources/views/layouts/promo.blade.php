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

    {{-- === ПРИЛИПАЮЩАЯ ШАПКА ВО ВСЮ ШИРИНУ === --}}
    {{-- Убрали mb-6 md:mb-10, чтобы следующий блок начинался сразу под шапкой --}}
    <header class="sticky top-0 w-full z-50 bg-[#FAF8F5]/80 backdrop-blur-md shadow-sm border-b border-[#E85C24]/10 transition-all duration-300">
        
        <div class="container mx-auto px-4 py-2 md:py-3 flex justify-start">
            
            <a href="/" class="flex flex-col items-start group">
                <img src="{{ asset('images/logo.png') }}" alt="Общество ревнителей санскрита" class="w-auto h-12 md:h-14 object-contain drop-shadow-sm group-hover:scale-105 transition-transform duration-300 shrink-0">
                
                <span class="mt-1 text-base md:text-lg font-semibold text-[#333333] group-hover:text-[#E85C24] transition-colors duration-300 leading-none" 
                      style="font-family: 'Charter', 'Bitstream Charter', 'Sitka Text', 'Georgia', serif;">
                    Общество ревнителей санскрита
                </span>
            </a>
            
        </div>
    </header>
    {{-- ========================= --}}

    {{-- СЮДА БУДУТ ВСТАВЛЯТЬСЯ НАШИ БЛОКИ --}}
    @yield('content')

    {{-- ФУТЕР (Теплый тон, трехколоночный) --}}
    <footer class="bg-[#F2EBE1] border-t border-[#E85C24]/10 text-gray-600 py-10 md:py-14">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center text-center md:text-left">

                {{-- 1. ЛЕВАЯ КОЛОНКА: Текстовые ссылки (Временно скрыты) --}}
                <div class="flex flex-col space-y-3 items-center md:items-start">
                    {{-- 
                    <a href="#about" class="text-sm font-semibold hover:text-[#E85C24] transition-colors duration-300">О преподавателе</a>
                    <a href="#courses" class="text-sm font-semibold hover:text-[#E85C24] transition-colors duration-300">Программа курса</a>
                    <a href="#otz" class="text-sm font-semibold hover:text-[#E85C24] transition-colors duration-300">Отзывы учеников</a>
                    <a href="#faq" class="text-sm font-semibold hover:text-[#E85C24] transition-colors duration-300">Частые вопросы</a>
                    --}}
                </div>

                {{-- 2. ЦЕНТР: Брендинг, Документы и Копирайт --}}
                <div class="flex flex-col items-center justify-center text-center">
                    
                    {{-- Название --}}
                    <div class="font-bold text-[#101010] text-lg md:text-xl leading-tight mb-3" style="font-family: 'Charter', 'Bitstream Charter', 'Sitka Text', 'Georgia', serif;">
                        Общество ревнителей санскрита
                    </div>
                    
                    {{-- Документы (Ссылки с анимированным подчеркиванием) --}}
                    <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-5 text-[13px] md:text-sm font-medium mb-4">
                        <button @click="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" 
                                class="relative text-gray-500 hover:text-[#E85C24] transition-colors duration-300 group">
                            Политика конфиденциальности
                            <span class="absolute -bottom-1 left-0 w-0 h-[1.5px] bg-[#E85C24] transition-all duration-300 group-hover:w-full"></span>
                        </button>
                        
                        <span class="hidden sm:block text-gray-300 text-xs">•</span>
                        
                        <button @click="viewDocument('Договор оферты', '/docs/oferta.pdf')" 
                                class="relative text-gray-500 hover:text-[#E85C24] transition-colors duration-300 group">
                            Публичная оферта
                            <span class="absolute -bottom-1 left-0 w-0 h-[1.5px] bg-[#E85C24] transition-all duration-300 group-hover:w-full"></span>
                        </button>
                    </div>

                    {{-- Копирайт --}}
                    <p class="text-gray-400 text-[13px] md:text-sm">
                        &copy; {{ date('Y') }} Все права защищены
                    </p>
                    
                </div>

                {{-- 3. ПРАВАЯ КОЛОНКА: Соцсети (В фирменном стиле) --}}
                <div class="flex justify-center md:justify-end gap-4">
                    {{-- Ссылка ВКонтакте --}}
                    <a href="https://vk.com/samskrtamru" target="_blank" rel="noopener noreferrer" 
                       class="w-12 h-12 flex items-center justify-center rounded-full bg-white border border-[#E85C24]/20 text-[#E85C24] hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] shadow-sm hover:shadow-md transition-all duration-300 group">
                        <i class="fab fa-vk text-xl group-hover:scale-110 transition-transform"></i>
                    </a>
                    
                    {{-- Ссылка Telegram --}}
                    <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer" 
                       class="w-12 h-12 flex items-center justify-center rounded-full bg-white border border-[#E85C24]/20 text-[#E85C24] hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] shadow-sm hover:shadow-md transition-all duration-300 group">
                        <i class="fab fa-telegram-plane text-xl group-hover:scale-110 transition-transform"></i>
                    </a>
                </div>

            </div>
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