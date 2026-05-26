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
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 md:gap-6 lg:gap-7 max-w-7xl mx-auto">

            @foreach($data['items'] as $item)
                @php
                    $isWide = $item['is_wide'] ?? false;
                    $hasDescription = !empty($item['description']);

                    // Иконка из медиатеки: Curator хранит ID — резолвим в URL.
                    // Поддерживаем и legacy-путь (например promo/icon.svg).
                    $rawIcon = $item['icon'] ?? null;
                    $iconUrl = null;
                    if ($rawIcon) {
                        $iconUrl = is_numeric($rawIcon)
                            ? \Awcodes\Curator\Models\Media::find($rawIcon)?->url
                            : asset('storage/' . ltrim($rawIcon, '/'));
                    }
                @endphp

                {{-- КАРТОЧКА --}}
                <div class="relative bg-white rounded-3xl p-7 md:p-8 border border-gray-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_22px_45px_rgba(232,92,36,0.12)] hover:border-[#E85C24]/20 hover:-translate-y-1 transition-all duration-500 group overflow-hidden flex flex-col
                            {{ $isWide ? 'lg:col-span-2' : 'lg:col-span-1' }}">

                    {{-- НОМЕР (крупный, бледно-оранжевый) --}}
                    <span class="absolute top-5 right-6 text-5xl md:text-6xl font-extrabold leading-none tabular-nums text-[#E85C24]/10 group-hover:text-[#E85C24]/25 transition-colors duration-500 select-none pointer-events-none">
                        {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </span>

                    {{-- ИКОНКА-ЧИП --}}
                    <div class="relative z-10 w-14 h-14 md:w-16 md:h-16 mb-6 rounded-2xl bg-gradient-to-br from-orange-50 to-orange-100/70 ring-1 ring-orange-100 flex items-center justify-center group-hover:scale-105 group-hover:-rotate-3 transition-transform duration-500 overflow-hidden">
                        @if($iconUrl)
                            <img src="{{ $iconUrl }}" alt="" class="w-8 h-8 md:w-9 md:h-9 object-contain">
                        @else
                            <svg class="w-7 h-7 md:w-8 md:h-8 text-[#E85C24]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        @endif
                    </div>

                    {{-- КОНТЕНТ --}}
                    <div class="relative z-10">
                        @if(!empty($item['title']))
                            <h3 class="text-lg md:text-xl font-bold text-[#101010] leading-snug tracking-tight group-hover:text-[#E85C24] transition-colors">
                                {{ $item['title'] }}
                            </h3>
                        @endif

                        {{-- Описание выводится только если оно есть --}}
                        @if($hasDescription)
                            <p class="mt-2.5 text-gray-500 text-sm md:text-base leading-relaxed">
                                {{ $item['description'] }}
                            </p>
                        @endif
                    </div>

                    {{-- Тонкая оранжевая линия-акцент снизу, появляется на hover --}}
                    <div class="mt-6 h-0.5 w-10 rounded-full bg-[#E85C24]/20 group-hover:w-16 group-hover:bg-[#E85C24]/60 transition-all duration-500"></div>

                </div>
            @endforeach
            
        </div>
        @endif

    </div>
</section>