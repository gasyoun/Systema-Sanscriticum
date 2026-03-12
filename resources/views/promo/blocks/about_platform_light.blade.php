@php
    $title = $block['data']['title'] ?? 'О нашей платформе';
    // Обрабатываем контент: nl2br преобразует переносы строк в <br>, e() экранирует HTML для безопасности
    $content = $lesson->content ?? $lesson->topic ?? $block['data']['content'] ?? '';
@endphp

<div class="py-16 md:py-24 relative z-10 bg-white"> 
    <div class="container mx-auto px-4 max-w-7xl relative">
        
        {{-- 
            ГЛАВНЫЙ КОНТЕЙНЕР "СТЕКЛО"
            - backdrop-blur-2xl: максимальное размытие заднего плана
            - bg-white/40: очень прозрачный белый фон
            - border-0: отключаем стандартную рамку
            - shadow-[...]: сложная многослойная диффузная тень для эффекта парения
        --}}
        <div class="relative overflow-hidden bg-white/40 rounded-3xl p-8 md:p-14 backdrop-blur-2xl shadow-[0_20px_50px_-10px_rgba(30,41,59,0.08),0_10px_20px_-5px_rgba(30,41,59,0.04)] transition-all duration-300 hover:shadow-[0_30px_70px_-10px_rgba(30,41,59,0.12),0_15px_30px_-5px_rgba(30,41,59,0.06)] group">
            
            {{-- 
                ГЛЯНЦЕВЫЙ БЛИК (Gloss)
                Абсолютно белый градиент, имитирующий отражение окна или источника света
            --}}
            <div class="absolute inset-0 z-0 pointer-events-none opacity-60 group-hover:opacity-100 transition-opacity duration-500"
                 style="background: linear-gradient(135deg, rgba(255,255,255,0.8) 0%, rgba(255,255,255,0) 40%, rgba(255,255,255,0) 60%, rgba(255,255,255,0.5) 100%);">
            </div>

            {{-- 
                ЭФФЕКТ СВЕТЯЩЕГОСЯ КОНТУРА (Edge Light)
                Создаем псевдо-рамку с помощью внутреннего градиента (inset shadow)
            --}}
            <div class="absolute inset-0 rounded-3xl z-10 pointer-events-none ring-1 ring-inset ring-white/80" 
                 style="box-shadow: inset 0 2px 3px 1px rgba(255,255,255,1), inset 0 -1px 2px 0px rgba(255,255,255,0.3);">
            </div>

            {{-- КОНТЕНТ БЛОКА --}}
            <div class="relative z-20 max-w-4xl mx-auto text-center">
                @if($title)
                    {{-- text-gray-950: глубокий, почти черный цвет для контраста --}}
                    <h2 class="text-2xl md:text-4xl font-extrabold text-gray-950 mb-6 tracking-tight">{{ $title }}</h2>
                    {{-- Голубой акцент --}}
                    <div class="w-24 h-1 bg-[#E85C24] mx-auto mb-10 rounded-full shadow-[0_0_15px_rgba(42,171,238,0.4)]"></div>
                @endif
                
                @if($content)
                    {{-- prose prose-sm prose-indigo text-gray-700 leading-relaxed space-y-6: стили контента --}}
                    <div class="prose prose-sm prose-indigo max-w-none text-gray-700 leading-relaxed space-y-6 text-base md:text-lg">
                        {!! $content !!}
                    </div>
                @endif
            </div>

        </div>

    </div>
</div>

{{-- Небольшой хак для старых версий Tailwind, если backdrop-blur не работает --}}
<style>
    @supports not (backdrop-filter: blur(0px)) {
        .backdrop-blur-2xl {
            background-color: rgba(255, 255, 255, 0.9) !important;
        }
    }
</style>