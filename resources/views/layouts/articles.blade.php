<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- Title и meta — каждая страница (show/index) переопределяет через @section('meta') --}}
    <title>@yield('title', 'Статьи — Общество ревнителей санскрита')</title>
    <meta name="description" content="@yield('meta_description', 'Статьи о санскрите, грамматике, философии и практике.')">

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">

    {{-- Open Graph — переопределяется страницами --}}
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('og_title', 'Статьи — Общество ревнителей санскрита')">
    <meta property="og:description" content="@yield('og_description', 'Статьи о санскрите.')">
    <meta property="og:image" content="@yield('og_image', asset('images/logo.png'))">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    {{-- Канонический URL — страницы могут переопределить --}}
    <link rel="canonical" href="@yield('canonical', url()->current())">

    {{-- Шрифты: Montserrat (основной) + Lora (serif в статьях) грузятся из article.css --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    {{-- Font Awesome (иконки часов, соцсетей и т.п. — используются и в шапке, и в статьях) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

{{-- ═══════════════ АНАЛИТИКА БЛОГА ═══════════════ --}}
@if(!empty($blogAnalytics['yandex_id']))
<script type="text/javascript">
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym({{ $blogAnalytics['yandex_id'] }}, "init", {
       clickmap:true,
       trackLinks:true,
       accurateTrackBounce:true,
       webvisor:true
   });
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{{ $blogAnalytics['yandex_id'] }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
@endif

@if(!empty($blogAnalytics['vk_id']))
<script type="text/javascript">
  var _tmr = window._tmr || (window._tmr = []);
  _tmr.push({id: "{{ $blogAnalytics['vk_id'] }}", type: "pageView", start: (new Date()).getTime()});
  (function (d, w, id) {
    if (d.getElementById(id)) return;
    var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
    ts.src = "https://top-fwz1.mail.ru/js/code.js";
    var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
    if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
  })(document, window, "tmr-code");
</script>
<noscript><div><img src="https://top-fwz1.mail.ru/counter?id={{ $blogAnalytics['vk_id'] }};js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
@endif

{{-- Прокидываем ID в JS, чтобы скрипты целей могли их использовать --}}
<script>
window.BLOG_ANALYTICS = {
    yandexId: @json($blogAnalytics['yandex_id']),
    vkId:     @json($blogAnalytics['vk_id']),
};

// Хелпер для отправки целей
window.sendGoal = function(goalName) {
    if (window.BLOG_ANALYTICS.yandexId && typeof ym !== 'undefined') {
        ym(window.BLOG_ANALYTICS.yandexId, 'reachGoal', goalName);
    }
    if (window.BLOG_ANALYTICS.vkId && typeof _tmr !== 'undefined') {
        _tmr.push({ type: 'reachGoal', id: window.BLOG_ANALYTICS.vkId, goal: goalName });
    }
};
</script>

    {{-- Основные стили сайта + специфичные для статей --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/article.css'])

    {{-- Alpine — нужен для модалок/интерактива в шапке и футере --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { font-family: 'Montserrat', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>

    {{-- Место для страниц добавить что-то в head (JSON-LD, доп. мета) --}}
    @stack('head')
</head>

<body class="bg-[#FAF8F5] text-gray-900 antialiased overflow-x-hidden"
      x-data="{
          openDoc: false, docTitle: '', docUrl: '',
          isTrialModalOpen: false,
          agreedForm: false,
          viewDocument(title, url) {
              this.docTitle = title;
              this.docUrl = url;
              this.openDoc = true;
          }
      }">

    {{-- ═══════════════ ОБЁРТКА СТАТЕЙ ═══════════════ --}}
    {{-- КЛАСС .article-page КРИТИЧЕН — все стили из article.css под ним заскоуплены --}}
    <div class="article-page">

        {{-- ═══════════════ ШАПКА САЙТА ═══════════════ --}}
        <header class="sticky top-0 w-full z-50 bg-[#FAF8F5]/80 backdrop-blur-md shadow-sm border-b border-[#E85C24]/10 transition-all duration-300">
            <div class="container mx-auto px-4 py-2 md:py-3 flex justify-between items-center">

                <a href="/" class="flex flex-col items-start group">
                    <img src="{{ asset('images/logo.png') }}" alt="Общество ревнителей санскрита"
                         class="w-auto h-12 md:h-14 object-contain drop-shadow-sm group-hover:scale-105 transition-transform duration-300 shrink-0">
                    <span class="mt-1 text-base md:text-lg font-semibold text-[#333333] group-hover:text-[#E85C24] transition-colors duration-300 leading-none"
                          style="font-family: 'Charter', 'Bitstream Charter', 'Sitka Text', 'Georgia', serif;">
                        Общество ревнителей санскрита
                    </span>
                </a>

                {{-- Правая часть шапки: ссылка на блог + CTA --}}
                <div class="flex items-center gap-3 md:gap-5">
                    <a href="{{ route('articles.index') }}"
                       class="hidden sm:inline-flex items-center text-sm font-semibold text-gray-700 hover:text-[#E85C24] transition-colors">
                        <i class="fas fa-newspaper mr-2"></i>
                        Статьи
                    </a>
                    <button type="button"
        @click="isTrialModalOpen = true"
        class="inline-flex items-center gap-2 px-4 md:px-5 py-2 md:py-2.5 bg-[#E85C24] hover:bg-[#c24a18] text-white text-xs md:text-sm font-bold uppercase tracking-wider rounded-xl shadow-md shadow-[#E85C24]/25 hover:shadow-lg transition-all">
    <i class="fas fa-graduation-cap"></i>
    <span>Записаться</span>
</button>
                </div>

            </div>
        </header>

        {{-- ═══════════════ КОНТЕНТ СТРАНИЦЫ ═══════════════ --}}
        @yield('content')

        {{-- ═══════════════ ФУТЕР ═══════════════ --}}
        <footer class="bg-[#F2EBE1] border-t border-[#E85C24]/10 text-gray-600 py-10 md:py-14 mt-20">
            <div class="container mx-auto px-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center text-center md:text-left">

                    <div class="flex flex-col space-y-3 items-center md:items-start">
                        {{-- Пусто — зарезервировано под навигацию --}}
                    </div>

                    <div class="flex flex-col items-center justify-center text-center">
                        <div class="font-bold text-[#101010] text-lg md:text-xl leading-tight mb-3"
                             style="font-family: 'Charter', 'Bitstream Charter', 'Sitka Text', 'Georgia', serif;">
                            Общество ревнителей санскрита
                        </div>

                        <div class="flex flex-col sm:flex-row items-center gap-3 sm:gap-5 text-[13px] md:text-sm font-medium mb-4">
                            <button @click="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')"
                                    class="relative text-gray-500 hover:text-[#E85C24] transition-colors duration-300 group">
                                Политика конфиденциальности
                                <span class="absolute -bottom-1 left-0 w-0 h-[1.5px] bg-[#E85C24] transition-all duration-300 group-hover:w-full"></span>
                            </button>
                            <span class="hidden sm:block text-gray-300 text-xs">•</span>
                            <button @click="viewDocument('Публичная оферта', '/docs/oferta.pdf')"
                                    class="relative text-gray-500 hover:text-[#E85C24] transition-colors duration-300 group">
                                Публичная оферта
                                <span class="absolute -bottom-1 left-0 w-0 h-[1.5px] bg-[#E85C24] transition-all duration-300 group-hover:w-full"></span>
                            </button>
                        </div>

                        <p class="text-gray-400 text-[13px] md:text-sm">
                            &copy; {{ date('Y') }} Все права защищены
                        </p>
                    </div>

                    <div class="flex justify-center md:justify-end gap-4">
                        <a href="https://vk.com/samskrtamru" target="_blank" rel="noopener noreferrer"
                           class="w-12 h-12 flex items-center justify-center rounded-full bg-white border border-[#E85C24]/20 text-[#E85C24] hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] shadow-sm hover:shadow-md transition-all duration-300 group">
                            <i class="fab fa-vk text-xl group-hover:scale-110 transition-transform"></i>
                        </a>
                        <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener noreferrer"
                           class="w-12 h-12 flex items-center justify-center rounded-full bg-white border border-[#E85C24]/20 text-[#E85C24] hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] shadow-sm hover:shadow-md transition-all duration-300 group">
                            <i class="fab fa-telegram-plane text-xl group-hover:scale-110 transition-transform"></i>
                        </a>
                    </div>

                </div>
            </div>
        </footer>

    </div> {{-- /.article-page --}}

    {{-- ═══════════════ МОДАЛКА PDF (общая для всех страниц блога) ═══════════════ --}}
    <div x-show="openDoc" x-cloak
         class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
         @click.self="openDoc = false"
         @keydown.escape.window="openDoc = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-200">
                <h3 class="text-base font-bold text-gray-900" x-text="docTitle"></h3>
                <button @click="openDoc = false" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors">
                    <i class="fas fa-times text-gray-500"></i>
                </button>
            </div>
            <iframe :src="docUrl" class="flex-1 w-full" frameborder="0"></iframe>
        </div>
    </div>

    {{-- Место для страниц добавить свои скрипты (прогресс-бар, fade-in, TOC) --}}
    
    {{-- Универсальная модалка пробного занятия --}}
<x-trial-modal :form-name="isset($article) ? 'Статья: ' . $article->title : 'Блог'" />

@stack('scripts')
</body>
</html>