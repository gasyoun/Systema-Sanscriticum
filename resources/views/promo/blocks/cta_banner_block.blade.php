@php
    $data = $block['data'] ?? [];
    
    // Контент
    $title = $data['title'] ?? 'ВОПРОСЫ И ОТВЕТЫ А.В.ПАРИБКУ';
    $subtitle = $data['subtitle'] ?? 'Посмотрите вводное занятие, где Андрей Всеволодович более развернуто отвечает на вопросы о курсе →';
    $buttonText = $data['button_text'] ?? 'СМОТРЕТЬ';
    $buttonUrl = $data['button_url'] ?? '#';
    
    // Дизайн
    $bgColor = $data['bg_color'] ?? '#4b9b74';
    $bgImage = $data['bg_image'] ?? null;
    $textColor = $data['text_color'] ?? '#ffffff';
    $buttonBgColor = $data['button_bg_color'] ?? '#ffffff';
    $buttonTextColor = $data['button_text_color'] ?? '#1E4633';

    // УМНЫЙ ПАРСЕР ССЫЛОК ДЛЯ ПЛЕЕРА
    $embedUrl = $buttonUrl;
    
    // YouTube
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $buttonUrl, $match)) {
        $embedUrl = "https://www.youtube.com/embed/" . $match[1] . "?autoplay=1";
    }
    // RuTube
    elseif (preg_match('/rutube\.ru\/video\/([a-zA-Z0-9_-]+)/i', $buttonUrl, $match)) {
        $embedUrl = "https://rutube.ru/play/embed/" . $match[1];
    }
    // VK Video (vk.com/video-12345_67890)
    elseif (preg_match('/vk\.com\/video(-?\d+)_(\d+)/i', $buttonUrl, $match)) {
        $embedUrl = "https://vk.com/video_ext.php?oid=" . $match[1] . "&id=" . $match[2] . "&hd=2&autoplay=1";
    }
    // Vimeo
    elseif (preg_match('/vimeo\.com\/(\d+)/i', $buttonUrl, $match)) {
        $embedUrl = "https://player.vimeo.com/video/" . $match[1] . "?autoplay=1";
    }
@endphp

{{-- x-data перенесли прямо в секцию, чтобы не ломать DOM и стики-элементы --}}
<section x-data="{ videoOpen: false }" class="py-4 lg:py-6 relative z-10 font-nunito">
    <div class="container mx-auto px-4 relative">
        
        {{-- Основной блок баннера --}}
        <div class="relative w-full rounded-2xl lg:rounded-[2rem] overflow-hidden shadow-[0_20px_40px_rgba(0,0,0,0.1)] py-6 px-6 md:py-8 md:px-10 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6 transition-transform hover:shadow-[0_25px_50px_rgba(0,0,0,0.15)]"
             style="background-color: {{ $bgColor }};">

            @if($bgImage)
                <div class="absolute inset-0 z-0 opacity-30 mix-blend-overlay bg-cover bg-center bg-no-repeat pointer-events-none"
                     style="background-image: url('{{ asset('storage/' . $bgImage) }}');"></div>
            @endif

            <div class="absolute inset-0 z-0 bg-gradient-to-r from-black/10 to-transparent pointer-events-none"></div>

            <div class="relative z-10 w-full lg:w-2/3 flex flex-col items-start text-left">
                <h2 class="text-xl md:text-2xl lg:text-[28px] font-extrabold uppercase tracking-wide mb-1.5 leading-tight" 
                    style="color: {{ $textColor }};">
                    {{ $title }}
                </h2>
                <p class="text-sm md:text-base font-medium opacity-90 leading-snug" 
                   style="color: {{ $textColor }};">
                    {{ $subtitle }}
                </p>
            </div>

            {{-- Кнопка с вызовом модального окна (@click.prevent) --}}
            <div class="relative z-10 shrink-0 w-full lg:w-auto mt-2 lg:mt-0">
                <a href="{{ $buttonUrl }}"
                   @click.prevent="videoOpen = true"
                   class="flex items-center justify-center w-full lg:w-auto px-10 py-4 rounded-[14px] font-extrabold text-sm uppercase tracking-widest transition-all duration-300 hover:-translate-y-1 active:translate-y-0 shadow-lg hover:shadow-xl group"
                   style="background-color: {{ $buttonBgColor }}; color: {{ $buttonTextColor }};">
                    <div class="w-2 h-2 rounded-full bg-current animate-pulse mr-3 opacity-70 group-hover:opacity-100"></div>
                    {{ $buttonText }}
                </a>
            </div>

        </div>
        
    </div>

    {{-- ========================================== --}}
    {{-- ВСПЛЫВАЮЩЕЕ ОКНО (МОДАЛКА) С ПЛЕЕРОМ       --}}
    {{-- ========================================== --}}
    <div x-show="videoOpen" 
         style="display: none;"
         class="fixed inset-0 z-[100] flex items-center justify-center bg-[#101010]/90 backdrop-blur-md p-4 sm:p-6"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        {{-- Подложка для закрытия --}}
        <div class="absolute inset-0 cursor-pointer" @click="videoOpen = false"></div>

        {{-- Контейнер плеера --}}
        <div class="relative w-full max-w-5xl bg-black rounded-2xl md:rounded-[2rem] overflow-hidden shadow-2xl z-10 aspect-video transform scale-95 md:scale-100"
             x-show="videoOpen"
             x-transition:enter="transition ease-out duration-300 delay-100"
             x-transition:enter-start="opacity-0 translate-y-8 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            
            {{-- Кнопка закрыть (Крестик) --}}
            <button @click="videoOpen = false" class="absolute top-4 right-4 md:top-6 md:right-6 w-10 h-10 bg-white/10 hover:bg-[#E85C24] text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-colors z-20">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            {{-- Iframe плеера --}}
            <template x-if="videoOpen">
                <iframe src="{{ $embedUrl }}" 
                        class="w-full h-full border-0 absolute inset-0" 
                        allow="autoplay; encrypted-media; fullscreen; picture-in-picture" 
                        allowfullscreen>
                </iframe>
            </template>
            
        </div>
    </div>

</section>