<section class="relative bg-white overflow-hidden pt-4 pb-10 lg:pt-10 lg:pb-16" x-data="{ loaded: false }" x-init="setTimeout(() => loaded = true, 100)">
    
    {{-- ДИНАМИЧЕСКИЙ ФОН --}}
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[1000px] h-[1000px] bg-orange-50/60 rounded-full blur-[120px] -z-20 pointer-events-none transition-transform duration-[3s]"
         :class="loaded ? 'scale-110 opacity-100' : 'scale-90 opacity-0'"></div>
    
    <div class="absolute top-20 left-10 w-32 h-32 bg-yellow-100/50 rounded-full blur-3xl -z-10 animate-float-slow"></div>
    <div class="absolute bottom-20 right-10 w-48 h-48 bg-red-50/50 rounded-full blur-3xl -z-10 animate-float-fast"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        <div class="max-w-7xl mx-auto flex flex-col">
            
            {{-- 1. ТЕКСТОВАЯ ЧАСТЬ --}}
            {{-- Родитель выравнивает всё влево (items-start) --}}
            <div class="flex flex-col items-start text-left">
                
                {{-- Надзаголовок --}}
                @if(!empty($data['subtitle']))
                    {{-- ДОБАВИЛ self-center, чтобы этот блок встал по центру, игнорируя родителя --}}
                    <div class="transform transition-all duration-700 delay-100 translate-y-8 opacity-0 self-center"
                         :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                        <div class="inline-block px-6 py-2 rounded-full bg-gradient-to-r from-orange-50 to-red-50 border border-orange-100 mb-6 shadow-sm hover:shadow-md transition-shadow cursor-default">
                            <span class="text-[#E3122C] font-bold text-xs md:text-sm uppercase tracking-[0.2em]">
                                {{ $data['subtitle'] }}
                            </span>
                        </div>
                    </div>
                @endif
                
                {{-- Заголовок (Остается слева) --}}
                <div class="transform transition-all duration-700 delay-200 translate-y-8 opacity-0 w-full"
                     :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                    <h1 class="text-4xl md:text-5xl font-extrabold text-[#101010] mb-8 leading-tight tracking-tight max-w-6xl">
                        {{ $data['title'] }}
                    </h1>
                </div>
                
                {{-- Описание (Остается слева) --}}
                @if(!empty($data['description']))
                    <div class="transform transition-all duration-700 delay-300 translate-y-8 opacity-0"
                         :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                        <div class="text-lg md:text-2xl text-gray-500 mb-10 leading-relaxed max-w-4xl font-medium text-opacity-90">
                            {!! nl2br(e($data['description'])) !!}
                        </div>
                    </div>
                @endif
            </div>

            {{-- 2. ИНТЕРАКТИВНАЯ ЧАСТЬ (ПО ЦЕНТРУ) --}}
            <div class="flex flex-col items-center text-center mt-4">
                {{-- Кнопка --}}
                <div class="transform transition-all duration-700 delay-500 translate-y-8 opacity-0"
                     :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                    <a href="#order-form-anchor" 
                       class="group relative inline-flex items-center justify-center bg-[#E3122C] text-white font-bold text-lg uppercase tracking-wider py-5 px-16 rounded-2xl shadow-xl shadow-red-500/30 overflow-hidden transition-all duration-300 hover:shadow-red-500/50 hover:-translate-y-1">
                        <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-shimmer"></div>
                        <span class="relative z-10">{{ $data['button_text'] ?? 'Записаться сейчас' }}</span>
                    </a>
                </div>

                {{-- Гарантии --}}
                <div class="transform transition-all duration-700 delay-700 translate-y-8 opacity-0"
                     :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                    <div class="mt-10 flex flex-wrap items-center justify-center gap-6 md:gap-10 text-sm font-bold text-gray-400 uppercase tracking-wide">
                        
                        <div class="flex items-center gap-2 group cursor-default transition-colors hover:text-gray-600">
                            <div class="w-2 h-2 rounded-full bg-green-500 group-hover:scale-125 transition-transform duration-300 shadow-[0_0_10px_rgba(34,197,94,0.5)]"></div>
                            <span>Старт потока скоро</span>
                        </div>

                        <div class="hidden md:block w-1 h-1 rounded-full bg-gray-200"></div>

                        <div class="flex items-center gap-2 group cursor-default transition-colors hover:text-gray-600">
                            <div class="w-2 h-2 rounded-full bg-green-500 group-hover:scale-125 transition-transform duration-300 shadow-[0_0_10px_rgba(34,197,94,0.5)]"></div>
                            <span>Места ограничены</span>
                        </div>

                        <div class="hidden md:block w-1 h-1 rounded-full bg-gray-200"></div>

                        <div class="flex items-center gap-2 group cursor-default transition-colors hover:text-gray-600">
                            <div class="w-2 h-2 rounded-full bg-green-500 group-hover:scale-125 transition-transform duration-300 shadow-[0_0_10px_rgba(34,197,94,0.5)]"></div>
                            <span>Онлайн формат</span>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </div>

    <style>
        @keyframes float-slow {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }
        @keyframes float-fast {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-15px) scale(0.95); }
        }
        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }
        .animate-float-slow { animation: float-slow 7s infinite ease-in-out; }
        .animate-float-fast { animation: float-fast 5s infinite ease-in-out; }
        .group-hover\:animate-shimmer:hover { animation: shimmer 1.5s infinite; }
    </style>
</section>