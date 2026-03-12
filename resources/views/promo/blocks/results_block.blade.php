<section class="py-16 lg:py-24 bg-white relative font-nunito overflow-hidden">
    
    {{-- Фоновый декор --}}
    <div class="absolute top-1/2 left-0 w-[40rem] h-[40rem] bg-orange-50 rounded-full blur-[100px] opacity-60 -translate-y-1/2 -translate-x-1/4 pointer-events-none z-0"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- ЗАГОЛОВОК --}}
        <div class="max-w-3xl mx-auto text-center flex flex-col items-center mb-16 lg:mb-24">
            <h2 class="text-2xl md:text-3xl lg:text-4xl font-extrabold text-[#101010] mb-5 tracking-tight">
                {{ $data['title'] ?? 'Вот, что могут 90% наших учеников' }}
            </h2>
            <div class="w-20 h-1.5 bg-[#E85C24] rounded-full mx-auto"></div>
        </div>

        @if(!empty($data['items']))
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-12 lg:gap-x-8 lg:gap-y-16 max-w-7xl mx-auto mt-10">
            
            @foreach($data['items'] as $item)
                @php
                    $isWide = $item['is_wide'] ?? false;
                    $hasDescription = !empty($item['description']);
                @endphp

                {{-- КАРТОЧКА --}}
                <div class="relative bg-white rounded-[2.5rem] p-8 md:p-10 pt-14 md:pt-16 shadow-[0_5px_25px_rgba(0,0,0,0.03)] border border-gray-100 hover:shadow-[0_30px_60px_rgba(232,92,36,0.1)] hover:border-[#E85C24]/20 transition-all duration-500 group flex flex-col justify-between
                            {{ $isWide ? 'lg:col-span-2' : 'lg:col-span-1' }}">
                    
                    {{-- ГИПЕР-ИКОНКА --}}
                    <div class="absolute -top-10 left-8 md:-top-12 md:left-10 w-20 h-20 md:w-24 md:h-24 rounded-[1.5rem] md:rounded-[2rem] bg-white shadow-[0_15px_35px_rgba(0,0,0,0.1)] border border-gray-50 flex items-center justify-center group-hover:-translate-y-3 group-hover:shadow-[0_20px_40px_rgba(232,92,36,0.25)] transition-all duration-500 z-10 overflow-hidden">
                        
                        <div class="absolute inset-0 bg-gradient-to-br from-gray-50/50 to-white pointer-events-none"></div>

                        @if(!empty($item['icon']))
                            <img src="{{ Storage::url($item['icon']) }}" alt="" class="relative z-10 w-12 h-12 md:w-14 md:h-14 object-contain group-hover:scale-110 transition-transform duration-500">
                        @else
                            <div class="relative z-10 w-12 h-12 md:w-14 md:h-14 rounded-full bg-orange-50 text-[#E85C24] flex items-center justify-center">
                                <svg class="w-6 h-6 md:w-8 md:h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            </div>
                        @endif
                    </div>

                    {{-- КОНТЕНТ --}}
                    <div class="relative z-0">
                        @if(!empty($item['title']))
                            <h3 class="text-lg md:text-xl font-bold text-[#101010] leading-tight group-hover:text-[#E85C24] transition-colors tracking-tight">
                                {{ $item['title'] }}
                            </h3>
                        @endif

                        {{-- Описание выводится только если оно есть --}}
                        @if($hasDescription)
                            <p class="mt-3 text-gray-500 text-sm md:text-base leading-relaxed opacity-90">
                                {{ $item['description'] }}
                            </p>
                        @endif
                    </div>

                    {{-- ФУТЕР КАРТОЧКИ --}}
                    <div class="mt-8 flex items-center justify-between border-t border-gray-50 pt-5">
                        {{-- Надпись Skill выводится только если есть описание, иначе скрываем --}}
                        @if($hasDescription)
                            <span class="text-[9px] font-black uppercase tracking-[0.2em] text-gray-300 group-hover:text-[#E85C24]/40 transition-colors">Skill</span>
                        @else
                            <span class="block"></span> {{-- Пустой блок для сохранения justify-between --}}
                        @endif

                        <div class="text-[#E85C24] opacity-0 -translate-x-4 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-500">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" /></svg>
                        </div>
                    </div>

                </div>
            @endforeach
            
        </div>
        @endif

    </div>
</section>