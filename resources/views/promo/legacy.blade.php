<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->title }}</title>
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $page->title }}">
    <meta property="og:description" content="{{ Str::limit($page->hero_description, 150) }}"> 
    @if($page->image_path)
    <meta property="og:image" content="{{ url(Storage::url($page->image_path)) }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"></noscript>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { font-family: 'Montserrat', sans-serif; }
        [x-cloak] { display: none !important; }
        
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        .animate-blob { animation: blob 7s infinite; }
        .animation-delay-2000 { animation-delay: 2s; }
        .animation-delay-4000 { animation-delay: 4s; }
    </style>

    @if($page->yandex_metrika_id)
    <script type="text/javascript" >
       (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
       m[i].l=1*new Date();
       for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
       k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
       (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

       ym({{ $page->yandex_metrika_id }}, "init", {
           clickmap:true,
           trackLinks:true,
           accurateTrackBounce:true,
           webvisor:true
       });
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/{{ $page->yandex_metrika_id }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    @endif

    @if($page->vk_pixel_id)
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
<body class="bg-white text-gray-900 antialiased selection:bg-[#E85C24] selection:text-white overflow-x-hidden"
      x-data="{ 
          openConsent: false, 
          targetUrl: '', 
          agreed: false,
          
          /* Логика для просмотра PDF */
          openDoc: false,
          docTitle: '',
          docUrl: '',

          askConsent(url) {
              this.targetUrl = url;
              this.agreed = false;
              this.openConsent = true;
          },
          
          proceed() {
              if (this.agreed) {
                  // 1. ОТПРАВЛЯЕМ СОБЫТИЕ (КЛИК ПО КНОПКЕ)
                  @if($page->yandex_metrika_id)
                      if (typeof ym !== 'undefined') {
                          ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'begin_checkout');
                      }
                  @endif
                  
                  @if($page->vk_pixel_id)
                      if (typeof _tmr !== 'undefined') {
                          _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'begin_checkout' });
                      }
                  @endif

                  // 2. ЗАДЕРЖКА 300мс ПЕРЕД ПЕРЕХОДОМ
                  setTimeout(() => {
                      window.location.href = this.targetUrl;
                  }, 300);
              }
          },
          
          viewDocument(title, url) {
              this.docTitle = title;
              this.docUrl = url;
              this.openDoc = true;
          }
      }">

    <section class="relative bg-[#F9FAFB] pt-12 pb-20 lg:pt-24 lg:pb-32 overflow-hidden" x-data="{ shown: false }" x-init="setTimeout(() => shown = true, 200)">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                
                <div class="lg:col-span-5 relative order-2 lg:order-1 transition-all duration-1000 transform translate-x-[-50px] opacity-0"
                     :class="shown ? '!translate-x-0 !opacity-100' : ''">
                    <div class="absolute top-4 left-4 w-full h-full rounded-[2.5rem] bg-gray-200 -z-10 transform rotate-2"></div>
                    
                    <div class="relative rounded-[2.5rem] overflow-hidden shadow-2xl border-[8px] md:border-[10px] border-white z-10 bg-white">
                        @if($page->image_path)
                            <img src="{{ Storage::url($page->image_path) }}" alt="Expert" fetchpriority="high" decoding="async" class="w-full h-auto object-cover transform transition duration-700 hover:scale-105">
                        @else
                            <div class="w-full h-[400px] md:h-[500px] bg-gray-100 flex items-center justify-center text-gray-400 flex-col">
                                <svg class="w-16 h-16 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span>Загрузите фото (портрет)</span>
                            </div>
                        @endif
                    </div>

                    @if($page->webinar_date)
                        <div class="absolute -bottom-6 -left-2 md:-left-8 bg-white p-5 md:p-6 rounded-2xl shadow-xl z-20 animate-bounce" style="animation-duration: 3s;">
                            <p class="text-3xl font-extrabold text-[#E85C24] leading-none mb-1">
                                {{ $page->webinar_date->format('d') }} 
                                <span class="text-gray-900 lowercase">
                                    {{ $page->webinar_date->translatedFormat('M') }}
                                </span>
                            </p>
                            <p class="text-[10px] text-gray-400 font-bold tracking-widest uppercase">
                                {{ $page->webinar_label ?? 'Бесплатный вебинар' }}
                            </p>
                        </div>
                    @endif
                </div>

                <div class="lg:col-span-7 pl-0 lg:pl-16 order-1 lg:order-2 text-center lg:text-left transition-all duration-1000 delay-300 transform translate-x-[50px] opacity-0"
                     :class="shown ? '!translate-x-0 !opacity-100' : ''">
                      
                    @if($page->subtitle)
                    <p class="text-[#E85C24] font-bold tracking-widest uppercase text-xs md:text-sm mb-6 inline-block bg-orange-50 px-3 py-1 rounded-md">
                        {{ $page->subtitle }}
                    </p>
                    @endif
                    
                    <h1 class="text-4xl md:text-6xl lg:text-7xl font-extrabold text-gray-900 mb-4 leading-tight">
                        {{ $page->title }}
                    </h1>
                    
                    @if($page->instructor_name)
                    <h2 class="text-3xl md:text-5xl font-serif text-[#E85C24] mb-8 flex flex-col md:flex-row items-center md:items-baseline justify-center lg:justify-start gap-2">
                        <span class="text-2xl md:text-4xl text-gray-400 font-bold font-sans">
                            {{ $page->instructor_label ?? 'с экспертом' }}
                            <span class="relative inline-block text-[#E85C24] group cursor-default ml-1 md:ml-2">
                                {{ $page->instructor_name }}
                                <span class="absolute -bottom-1 left-0 w-full h-1 bg-[#E85C24] opacity-30 rounded transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-left"></span>
                            </span>
                        </span>
                    </h2>
                    @endif
                    
                    @if($page->hero_description)
                    <div class="text-lg text-gray-600 mb-10 leading-relaxed max-w-2xl mx-auto lg:mx-0">
                         {{ $page->hero_description }}
                    </div>
                    @endif

                    @if($page->bullet_1 || $page->bullet_2)
                    <div class="flex flex-col gap-4 mb-12 items-center lg:items-start">
                        @if($page->bullet_1)
                        <div class="flex items-center text-gray-700 font-medium bg-white px-4 py-2 rounded-lg shadow-sm w-full md:w-auto border border-gray-100 transition hover:shadow-md hover:-translate-y-0.5">
                            <span class="w-6 h-6 bg-orange-100 text-[#E85C24] rounded-full flex items-center justify-center mr-3 text-sm font-bold shrink-0">✓</span>
                            {{ $page->bullet_1 }}
                        </div>
                        @endif
                        @if($page->bullet_2)
                        <div class="flex items-center text-gray-700 font-medium bg-white px-4 py-2 rounded-lg shadow-sm w-full md:w-auto border border-gray-100 transition hover:shadow-md hover:-translate-y-0.5">
                            <span class="w-6 h-6 bg-orange-100 text-[#E85C24] rounded-full flex items-center justify-center mr-3 text-sm font-bold shrink-0">✓</span>
                            {{ $page->bullet_2 }}
                        </div>
                        @endif
                    </div>
                    @endif

                    <div>
                        @if($page->telegram_url)
                            <a href="{{ $page->telegram_url }}" 
                               @click.prevent="askConsent('{{ $page->telegram_url }}')"
                               class="group inline-flex items-center justify-center bg-[#E85C24] text-white font-bold py-5 px-12 rounded-xl shadow-lg shadow-orange-500/30 hover:bg-[#d04a15] hover:-translate-y-1 transition-all duration-300 text-lg cursor-pointer">
                                {{ $page->button_text }}
                                <svg class="w-5 h-5 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </a>
                        @else
                            <a href="#order-form-anchor" 
                                class="group inline-flex items-center justify-center bg-[#E85C24] text-white font-bold py-5 px-12 rounded-xl shadow-lg shadow-orange-500/30 hover:bg-[#d04a15] hover:-translate-y-1 transition-all duration-300 text-lg cursor-pointer">
                                {{ $page->button_text }}
                                <svg class="w-5 h-5 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </a>
                        @endif
                        
                        @if($page->button_subtext)
                        <p class="mt-4 text-xs text-gray-400">{{ $page->button_subtext }}</p>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </section>


    <div class="py-24 bg-white relative overflow-hidden">
        <div class="container mx-auto px-4 relative z-10">
            <h2 class="text-3xl md:text-5xl font-bold text-center text-gray-900 mb-16">
                {{ $page->features_title ?? 'Особенности обучения' }}
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                
                <div class="group relative p-8 bg-white rounded-[2rem] border border-gray-100 hover:border-orange-200 transition-all duration-500 hover:shadow-2xl hover:shadow-orange-500/10 hover:-translate-y-2" x-data="{ hover: false }" @mouseenter="hover = true" @mouseleave="hover = false">
                    <div class="w-20 h-20 mb-8 rounded-2xl bg-orange-50 shadow-sm flex items-center justify-center transition-all duration-500 group-hover:bg-[#E85C24] group-hover:rotate-3">
                        <svg class="w-10 h-10 text-[#E85C24] transition-all duration-500 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4 group-hover:text-[#E85C24] transition-colors">
                        {{ $page->feature_1_title ?? 'Системность' }}
                    </h3>
                    <p class="text-gray-500 leading-relaxed">
                        {{ $page->feature_1_text ?? 'Строгая методология. Мы не учим "по верхам", а даем академический фундамент грамматики и философии.' }}
                    </p>
                </div>

                <div class="group relative p-8 bg-white rounded-[2rem] border border-gray-100 hover:border-orange-200 transition-all duration-500 hover:shadow-2xl hover:shadow-orange-500/10 hover:-translate-y-2" x-data="{ hover: false }" @mouseenter="hover = true" @mouseleave="hover = false">
                    <div class="w-20 h-20 mb-8 rounded-2xl bg-orange-50 shadow-sm flex items-center justify-center transition-all duration-500 group-hover:bg-[#E85C24] group-hover:-rotate-3">
                        <svg class="w-10 h-10 text-[#E85C24] transition-all duration-500 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4 group-hover:text-[#E85C24] transition-colors">
                        {{ $page->feature_2_title ?? 'Первоисточники' }}
                    </h3>
                    <p class="text-gray-500 leading-relaxed">
                        {{ $page->feature_2_text ?? 'Работа с реальными текстами. Вы начнете читать шлоки из Бхагавад-гиты и Упанишад уже на первом месяце.' }}
                    </p>
                </div>

                <div class="group relative p-8 bg-white rounded-[2rem] border border-gray-100 hover:border-orange-200 transition-all duration-500 hover:shadow-2xl hover:shadow-orange-500/10 hover:-translate-y-2" x-data="{ hover: false }" @mouseenter="hover = true" @mouseleave="hover = false">
                    <div class="w-20 h-20 mb-8 rounded-2xl bg-orange-50 shadow-sm flex items-center justify-center transition-all duration-500 group-hover:bg-[#E85C24] group-hover:rotate-3">
                        <svg class="w-10 h-10 text-[#E85C24] transition-all duration-500 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4 group-hover:text-[#E85C24] transition-colors">
                        {{ $page->feature_3_title ?? 'Обратная связь' }}
                    </h3>
                    <p class="text-gray-500 leading-relaxed">
                        {{ $page->feature_3_text ?? 'Живая проверка заданий. Куратор разбирает ошибки и помогает понять сложные моменты, а не просто ставит галочки.' }}
                    </p>
                </div>

            </div>
        </div>
    </div>


    {{-- СЕКЦИЯ: ВИДЕО + ОПИСАНИЕ + ФОРМА --}}
    {{-- py-24: Большие отступы сверху и снизу --}}
    <div class="bg-gray-50 py-24 border-t border-gray-200">
        <div class="container mx-auto px-4">
            
            {{-- 1. БЛОК ВИДЕО --}}
            @if($page->video_url)
            <div class="mb-16 text-center"> 
                <div class="text-center mb-10">
                    <h2 class="text-3xl md:text-5xl font-extrabold text-gray-900 tracking-tight">
                        Видео <span class="text-[#E85C24]">анонс</span>
                    </h2>
                    <div class="w-24 h-2 bg-[#E85C24] mx-auto mt-5 rounded-full opacity-90 shadow-lg shadow-orange-200"></div>
                </div>

                <div class="relative rounded-3xl overflow-hidden shadow-xl group border border-gray-100 max-w-5xl mx-auto">
                    <div class="relative w-full" style="padding-top: 56.25%;">
                        <iframe src="{{ $page->video_url }}" 
                                class="absolute top-0 left-0 w-full h-full" 
                                frameborder="0" 
                                allow="autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock;" 
                                allowfullscreen>
                        </iframe>
                    </div>
                </div>
            </div>
            @endif

            {{-- 2. СЕТКА (GRID) --}}
            {{-- items-stretch: Гарантирует, что левый и правый блок одной высоты --}}
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch" id="order-form-anchor">

                {{-- ЛЕВАЯ КОЛОНКА: ТЕКСТ (ШИРОКАЯ - 8/12) --}}
                <div class="lg:col-span-8 flex flex-col">
                    <div class="relative bg-white rounded-3xl p-8 md:p-12 shadow-lg border border-gray-100 h-full">
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-[#E85C24]"></div>
                        
                        {{-- Декор --}}
                        <div class="absolute -right-6 -top-6 text-gray-50 opacity-60 pointer-events-none">
                            <svg class="w-40 h-40" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm0 22c-5.523 0-10-4.477-10-10S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-15h2v6h-2zm0 8h2v2h-2z"/></svg>
                        </div>

                        <div class="relative z-10">
                            <h3 class="text-xs font-bold text-[#E85C24] uppercase tracking-widest mb-6">Детали программы</h3>
                            <div class="prose prose-lg prose-slate max-w-none custom-content">
                                {!! $page->description !!}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ПРАВАЯ КОЛОНКА: ФОРМА (УЗКАЯ - 4/12) --}}
                <div class="lg:col-span-4 flex flex-col">
                    {{-- h-full: Растягиваем на всю высоту --}}
                    {{-- justify-center: Центрируем контент по вертикали --}}
                    <div class="bg-gray-900 rounded-[2rem] p-6 md:p-8 shadow-2xl shadow-gray-900/30 relative overflow-hidden h-full flex flex-col justify-center" 
                         x-data="{ agreedForm: true, agreedPromoForm: true }">
                        
                        {{-- Декор формы --}}
                        <div class="absolute top-0 right-0 w-48 h-48 bg-[#E85C24] rounded-full mix-blend-screen filter blur-3xl opacity-15 pointer-events-none"></div>
                        <div class="absolute bottom-0 left-0 w-32 h-32 bg-purple-600 rounded-full mix-blend-screen filter blur-3xl opacity-10 pointer-events-none"></div>

                        <div class="relative z-10 w-full"> {{-- w-full чтобы контент не плющило --}}
                            <h3 class="text-xl font-bold text-white mb-2">Записаться на курс</h3>
                            <p class="text-gray-400 mb-6 text-sm">Оставьте заявку, и мы свяжемся с вами в Telegram.</p>

                            @if(session('success'))
                                <div class="p-3 mb-4 rounded-lg bg-green-500/20 border border-green-500/50 text-green-300 text-center font-bold text-sm">
                                    {{ session('success') }}
                                </div>
                            @endif

                            <form action="{{ route('leads.store') }}" method="POST" class="space-y-4 text-left">
                                @csrf
                                <input type="hidden" name="landing_page_id" value="{{ $page->id }}">
                                
                                {{-- Аналитика --}}
                                <input type="hidden" name="utm_source" class="analytics-field">
                                <input type="hidden" name="utm_medium" class="analytics-field">
                                <input type="hidden" name="utm_campaign" class="analytics-field">
                                <input type="hidden" name="utm_content" class="analytics-field">
                                <input type="hidden" name="utm_term" class="analytics-field">
                                <input type="hidden" name="click_id" class="analytics-field">
                                <input type="hidden" name="referrer" class="analytics-field" value="{{ request()->headers->get('referer') }}">

                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Ваше имя</label>
                                        <input type="text" name="name" required 
                                               class="w-full px-4 py-3 rounded-xl border-none bg-white/5 text-white placeholder-gray-500 focus:bg-white/10 focus:ring-1 focus:ring-[#E85C24] transition text-sm" 
                                               placeholder="Иван">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Телефон / Telegram</label>
                                        <input type="text" name="contact" required 
                                               class="w-full px-4 py-3 rounded-xl border-none bg-white/5 text-white placeholder-gray-500 focus:bg-white/10 focus:ring-1 focus:ring-[#E85C24] transition text-sm" 
                                               placeholder="+7 999 000-00-00">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Email</label>
                                        <input type="email" name="email" required 
                                               class="w-full px-4 py-3 rounded-xl border-none bg-white/5 text-white placeholder-gray-500 focus:bg-white/10 focus:ring-1 focus:ring-[#E85C24] transition text-sm" 
                                               placeholder="mail@example.com">
                                    </div>
                                </div>

                                {{-- БЛОК СОГЛАСИЙ (Блочная верстка, как ты просил) --}}
                                <div class="space-y-3 pt-2">
                                    
                                    {{-- 1. Согласие ПД --}}
                                    <label class="flex items-start gap-3 text-left p-3 rounded-xl cursor-pointer transition-all duration-300 border border-white/10 bg-white/5 hover:bg-white/10 hover:border-white/20 group">
                                        <div class="flex items-center h-5 mt-0.5 shrink-0">
                                            <input type="checkbox" x-model="agreedForm" class="w-5 h-5 rounded border-white/20 bg-transparent text-[#E85C24] focus:ring-[#E85C24] checked:bg-[#E85C24] checked:border-transparent cursor-pointer transition-colors">
                                        </div>
                                        <div class="text-xs text-gray-400 leading-relaxed select-none group-hover:text-gray-200 transition">
                                            Я даю <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                                        </div>
                                    </label>

                                    {{-- 2. Рассылка --}}
                                    <label class="flex items-start gap-3 text-left p-3 rounded-xl cursor-pointer transition-all duration-300 border border-white/10 bg-white/5 hover:bg-white/10 hover:border-white/20 group">
                                        <div class="flex items-center h-5 mt-0.5 shrink-0">
                                            <input type="checkbox" name="is_promo_agreed" x-model="agreedPromoForm" class="w-5 h-5 rounded border-white/20 bg-transparent text-[#E85C24] focus:ring-[#E85C24] checked:bg-[#E85C24] checked:border-transparent cursor-pointer transition-colors">
                                        </div>
                                        <div class="text-xs text-gray-400 leading-relaxed select-none group-hover:text-gray-200 transition">
                                            Я даю <span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие на получение рассылки</span>
                                        </div>
                                    </label>

                                </div>

                                <button type="submit" 
                                        :disabled="!agreedForm"
                                        :class="agreedForm ? 'bg-[#E85C24] hover:bg-[#d04a15] shadow-lg shadow-orange-500/20 transform hover:scale-[1.02] text-white' : 'bg-white/10 text-gray-500 cursor-not-allowed'"
                                        class="w-full font-bold py-4 rounded-xl transition-all duration-300 text-sm uppercase tracking-wide mt-2">
                                    {{ $page->button_text }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Стили для текста --}}
            <style>
                .custom-content p { margin-bottom: 1.2rem; line-height: 1.6; color: #374151; }
                .custom-content h1, .custom-content h2, .custom-content h3 { color: #111827; font-weight: 800; margin-top: 1.5rem; margin-bottom: 0.8rem; }
                .custom-content ul { list-style: none; padding-left: 0; margin-top: 1rem; margin-bottom: 1.5rem; }
                .custom-content ul li { position: relative; padding-left: 2rem; margin-bottom: 0.8rem; color: #4b5563; }
                .custom-content ul li::before { content: '✓'; position: absolute; left: 0; top: 2px; width: 1.2rem; height: 1.2rem; background-color: rgba(232, 92, 36, 0.1); color: #E85C24; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.7rem; }
            </style>
        </div> 
    </div>
    <footer class="bg-gray-900 text-gray-400 py-10 border-t border-gray-800 text-sm">
        <div class="container mx-auto px-4 text-center">
            <p class="mb-4">&copy; {{ date('Y') }} Все права защищены.</p>
            <button @click="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-gray-500 hover:text-white underline transition-colors duration-300">
                Политика конфиденциальности
            </button>
        </div>
    </footer>

    <div x-show="openDoc" 
         style="display: none;"
         class="fixed inset-0 z-[60] flex items-center justify-center p-2 sm:p-4"
         role="dialog" 
         aria-modal="true">
        
        <div x-show="openDoc" @click="openDoc = false" x-transition.opacity class="fixed inset-0 bg-black/80 backdrop-blur-sm"></div>

        <div x-show="openDoc" x-transition.scale.95 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[90vh] flex flex-col overflow-hidden z-10">
            <div class="flex items-center justify-between p-4 sm:p-6 border-b border-gray-100 bg-gray-50">
                <h3 class="text-lg sm:text-xl font-bold text-gray-900" x-text="docTitle"></h3>
                <button @click="openDoc = false" class="text-gray-400 hover:text-gray-600 transition p-2 rounded-full hover:bg-gray-200 bg-white shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="flex-1 w-full bg-gray-200 relative">
                <div class="absolute inset-0 flex items-center justify-center text-gray-400"><i class="fas fa-spinner fa-spin text-3xl"></i></div>
                <template x-if="openDoc"><iframe :src="docUrl" class="w-full h-full relative z-10 border-0"></iframe></template>
            </div>
            <div class="p-4 border-t border-gray-100 bg-white text-right flex justify-between items-center">
                <a :href="docUrl" target="_blank" class="text-sm text-indigo-600 hover:underline flex items-center"><i class="fas fa-external-link-alt mr-2"></i> Открыть в новой вкладке</a>
                <button @click="openDoc = false" class="px-6 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800 transition shadow-md">Закрыть</button>
            </div>
        </div>
    </div>

    <div x-show="openConsent" 
         style="display: none;"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         role="dialog" 
         aria-modal="true">
        
        <div x-show="openConsent" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="openConsent = false" class="fixed inset-0 bg-black/80 backdrop-blur-md"></div>

        <div x-show="openConsent" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-90 translate-y-4" class="relative bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 sm:p-8 text-center z-10">
            
            <div class="mb-6">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-orange-100 mb-4">
                    <svg class="h-6 w-6 text-[#E85C24]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900">Подтверждение</h3>
                <p class="text-sm text-gray-500 mt-2">Для продолжения необходимо ваше согласие с условиями.</p>
            </div>

            <div class="space-y-3 mb-8" x-data="{ agreed: true, agreedPromo: true }">
                
                <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                    <div class="flex items-center h-5 mt-0.5 shrink-0">
                        <input type="checkbox" x-model="agreed" class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                    </div>
                    <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                        Я даю <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                    <div class="flex items-center h-5 mt-0.5 shrink-0">
                        <input type="checkbox" x-model="agreedPromo" class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                    </div>
                    <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                        Я даю <span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие на получение рассылки</span>
                    </div>
                </label>

                <div class="grid gap-3 mt-6">
                    <button @click="$dispatch('proceed-click'); proceed()" 
                            :disabled="!agreed"
                            :class="agreed ? 'bg-[#E85C24] hover:bg-[#d04a15] shadow-lg shadow-orange-500/30' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                            class="w-full text-white font-bold py-3.5 rounded-xl transition-all duration-300 text-lg flex items-center justify-center">
                        Продолжить
                    </button>
                    <button @click="openConsent = false" class="text-gray-400 text-sm hover:text-gray-600 font-medium py-2">Отмена</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Цель: 60 секунд
            setTimeout(function() {
                @if($page->yandex_metrika_id)
                if (typeof ym !== 'undefined') ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'time_60s');
                @endif
                @if($page->vk_pixel_id)
                if (typeof _tmr !== 'undefined') _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'time_60s'});
                @endif
            }, 60000); 

            // Цель: Скролл
            let actionBlock = document.getElementById('order-form-anchor');
            if (actionBlock) {
                let observer = new IntersectionObserver(function(entries) {
                    if (entries[0].isIntersecting) {
                        @if($page->yandex_metrika_id)
                        if (typeof ym !== 'undefined') ym({{ $page->yandex_metrika_id }}, 'reachGoal', 'scroll_bottom');
                        @endif
                        @if($page->vk_pixel_id)
                        if (typeof _tmr !== 'undefined') _tmr.push({ type: 'reachGoal', id: {{ $page->vk_pixel_id }}, goal: 'scroll_bottom'});
                        @endif
                        observer.disconnect(); 
                    }
                });
                observer.observe(actionBlock);
            }
        });
    </script>
</body>
</html>