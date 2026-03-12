<section class="py-16 lg:py-24 bg-white relative font-nunito overflow-hidden">
    
    {{-- Декоративное свечение на фоне (слева) --}}
    <div class="absolute top-0 left-0 w-[30rem] h-[30rem] bg-orange-50 rounded-full blur-[80px] opacity-70 -translate-x-1/2 -translate-y-1/4 pointer-events-none"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- Заголовок --}}
        <div class="max-w-3xl mx-auto text-center mb-12 lg:mb-16">
    <h2 class="text-2xl md:text-3xl lg:text-4xl font-extrabold text-[#101010] mb-5 tracking-tight">
        {{ $data['title'] ?? 'Для кого этот курс' }}
    </h2>
    <div class="w-20 h-1.5 bg-[#E85C24] mx-auto rounded-full"></div>
</div>

        @if(!empty($data['items']))
        
        {{-- СЕТКА КАРТОЧЕК (Идеально ровная за счет Grid) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8 max-w-7xl mx-auto">
            
            @foreach($data['items'] as $item)
                {{-- ПРЕМИУМ-КАРТОЧКА --}}
                <div class="relative bg-white rounded-[2rem] p-8 md:p-10 border border-gray-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_20px_40px_rgba(232,92,36,0.08)] hover:border-[#E85C24]/30 hover:-translate-y-2 transition-all duration-500 group flex flex-col h-full overflow-hidden">
                    
                    {{-- Декоративный градиентный уголок (появляется при наведении) --}}
                    <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-bl from-orange-50 to-transparent rounded-bl-full opacity-0 group-hover:opacity-100 transition-opacity duration-500 z-0"></div>

                    {{-- Верхняя часть: Иконка и крупный номер --}}
                    <div class="mb-8 flex items-start justify-between relative z-10">
                        {{-- Иконка (Меняет цвет при наведении) --}}
                        <div class="w-14 h-14 rounded-2xl bg-gray-50 group-hover:bg-[#E85C24] group-hover:shadow-[0_8px_20px_rgba(232,92,36,0.3)] transition-all duration-500 flex items-center justify-center border border-gray-100 group-hover:border-[#E85C24] shrink-0">
                            <svg class="w-6 h-6 text-gray-400 group-hover:text-white transition-colors duration-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        
                        {{-- Скрытый крупный номер карточки (01, 02, 03...) --}}
                        <div class="text-5xl lg:text-6xl font-black text-gray-50 group-hover:text-orange-50 transition-colors duration-500 select-none -mt-2 -mr-2">
                            {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </div>
                    </div>

                    {{-- Заголовок карточки --}}
                    @if(!empty($item['title']))
                        <h3 class="text-xl md:text-2xl font-extrabold text-[#101010] mb-4 leading-snug group-hover:text-[#E85C24] transition-colors relative z-10">
                            {{ $item['title'] }}
                        </h3>
                    @endif

                    {{-- Текст --}}
                    @if(!empty($item['description']))
                        <div class="text-gray-500 text-base md:text-lg leading-relaxed flex-grow relative z-10">
                            {{ $item['description'] }}
                        </div>
                    @endif

                </div>
            @endforeach

        </div>
        @endif

    </div>
</section>