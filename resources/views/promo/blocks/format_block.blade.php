<section class="py-16 lg:py-24 bg-white relative font-nunito overflow-hidden">
    
    {{-- Легкий декоративный блик на фоне --}}
    <div class="absolute top-0 right-0 w-[30rem] h-[30rem] bg-orange-50 rounded-full blur-[80px] opacity-60 translate-x-1/3 -translate-y-1/4 pointer-events-none"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- ========================================== --}}
        {{-- ЗАГОЛОВОК (По нашему стандарту)            --}}
        {{-- ========================================== --}}
        <div class="max-w-3xl mx-auto text-center flex flex-col items-center mb-16 lg:mb-20">
            <h2 class="text-3xl md:text-4xl lg:text-4xl font-extrabold text-[#101010] mb-5 tracking-tight">
                {{ $data['title'] ?? 'Формат обучения' }}
            </h2>
            <div class="w-20 h-1.5 bg-[#E85C24] rounded-full mx-auto"></div>
        </div>

        @if(!empty($data['items']))
        
        {{-- УМНАЯ СЕТКА КАРТОЧЕК --}}
        <div class="flex flex-wrap justify-center gap-6 lg:gap-8 max-w-7xl mx-auto">
            
            @foreach($data['items'] as $item)
                {{-- ПРЕМИАЛЬНАЯ КАРТОЧКА --}}
                <div class="w-full sm:w-[calc(50%-12px)] lg:flex-1 lg:min-w-[240px] max-w-[360px] relative bg-gray-50 rounded-[2rem] p-8 md:p-10 border border-transparent shadow-sm hover:bg-white hover:shadow-[0_20px_40px_rgba(232,92,36,0.08)] hover:border-[#E85C24]/20 hover:-translate-y-2 transition-all duration-500 group flex flex-col items-center justify-center text-center overflow-hidden">
                    
                    {{-- Декоративный градиентный уголок (появляется при наведении) --}}
                    <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-bl from-orange-50 to-transparent rounded-full opacity-0 group-hover:opacity-100 transition-opacity duration-500 z-0"></div>

                    {{-- Значение (Уменьшенный и аккуратный шрифт) --}}
                    <div class="relative z-10 text-2xl md:text-2xl font-extrabold text-[#101010] mb-3 group-hover:text-[#E85C24] group-hover:scale-105 transition-transform duration-500 tracking-tight">
                        {{ $item['value'] }}
                    </div>

                    {{-- Разделительная мини-линия (расширяется при наведении) --}}
                    <div class="w-6 h-1 bg-gray-200 rounded-full mb-4 group-hover:w-12 group-hover:bg-[#E85C24]/50 transition-all duration-500 relative z-10"></div>

                    {{-- Подпись --}}
                    <p class="relative z-10 text-gray-500 text-xs md:text-sm font-bold uppercase tracking-widest leading-relaxed">
                        {{ $item['label'] }}
                    </p>
                    
                </div>
            @endforeach
            
        </div>
        @endif
        
    </div>
</section>