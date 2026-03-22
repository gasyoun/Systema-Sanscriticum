<section class="py-12 lg:py-20 bg-[#F9FAFB] font-nunito relative overflow-hidden">
    
    {{-- Скрытый фоновый блик --}}
    <div class="absolute top-0 right-0 w-[40rem] h-[40rem] bg-[#E85C24]/5 rounded-full blur-[100px] pointer-events-none -translate-y-1/2 translate-x-1/3"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        <div class="max-w-6xl mx-auto bg-white rounded-[3rem] p-8 md:p-12 shadow-xl shadow-gray-200/50">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
                
                {{-- ========================================== --}}
                {{-- ЛЕВАЯ КОЛОНКА: ФОТО + ПУБЛИКАЦИИ           --}}
                {{-- ========================================== --}}
                <div class="lg:col-span-5 flex flex-col">
                    
    {{-- 1. БЛОК ФОТО --}}
    <div class="relative mb-10">
        <div class="absolute top-4 left-4 w-full h-full border-2 border-[#E85C24]/30 rounded-[2.5rem] -z-0 translate-x-2 translate-y-2"></div>
        
        <div class="relative rounded-[2.5rem] overflow-hidden aspect-[4/5] z-10 bg-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.1)]">
            @php
                $instructorImageUrl = !empty($data['image']) ? \Awcodes\Curator\Models\Media::find($data['image'])?->url : null;
            @endphp
            
            @if($instructorImageUrl)
                <img src="{{ $instructorImageUrl }}" alt="{{ $data['name'] ?? '' }}" class="w-full h-full object-cover object-top">
            @else
                <div class="w-full h-full flex items-center justify-center text-gray-300">
                    <i class="fas fa-user text-6xl"></i>
                </div>
            @endif
        </div>

        {{-- Мобильная плашка --}}
        <div class="absolute bottom-6 left-6 right-6 bg-white/95 backdrop-blur-md p-4 rounded-2xl shadow-lg border border-white/50 text-center lg:hidden z-20">
            <h3 class="font-extrabold text-[#101010] text-xl">{{ $data['name'] ?? '' }}</h3>
            <p class="text-[#E85C24] text-sm font-bold uppercase tracking-wider mt-1">{{ $data['role'] ?? '' }}</p>
        </div>
    </div>

    {{-- 2. ПУБЛИКАЦИИ (Сетка 3 в ряд под фото) --}}
    @if(!empty($data['publications']))
        <div class="pt-4 lg:pt-2">
            <h3 class="text-lg md:text-xl font-extrabold text-[#101010] mb-6 flex items-center justify-center lg:justify-start">
                Публикации автора
                <span class="ml-3 px-2 py-0.5 bg-gray-100 text-gray-500 text-[10px] rounded-full">{{ count($data['publications']) }}</span>
            </h3>
            
            {{-- Сетка 3 в ряд --}}
            <div class="grid grid-cols-3 gap-3 md:gap-5">
                @foreach($data['publications'] as $pub)
                    @php
                        $tag = !empty($pub['url']) ? 'a' : 'div';
                        $href = !empty($pub['url']) ? 'href="'.$pub['url'].'" target="_blank"' : '';
                        // Достаем картинку книги из Curator
                        $pubImageUrl = !empty($pub['image']) ? \Awcodes\Curator\Models\Media::find($pub['image'])?->url : null;
                    @endphp
                    
                    <{{ $tag }} {!! $href !!} class="group flex flex-col items-start {{ !empty($pub['url']) ? 'cursor-pointer' : 'cursor-default' }}">
                        
                        {{-- Вертикальная обложка --}}
                        <div class="w-full aspect-[2/3] bg-gray-200 rounded-lg overflow-hidden relative shadow-[4px_4px_10px_rgba(0,0,0,0.12)] border-l-2 border-white/60 mb-3 transition-all duration-300 group-hover:-translate-y-1.5 group-hover:shadow-[4px_10px_20px_rgba(232,92,36,0.25)]">
                            @if($pubImageUrl)
                                <img src="{{ $pubImageUrl }}" alt="{{ $pub['title'] ?? '' }}" class="absolute inset-0 w-full h-full object-cover">
                            @else
                                <div class="absolute inset-0 flex items-center justify-center bg-gray-100 text-gray-300">
                                    <i class="fas fa-book text-xl md:text-2xl"></i>
                                </div>
                            @endif
                            {{-- Эффект глянца/блика на обложке --}}
                            <div class="absolute inset-0 bg-gradient-to-tr from-transparent via-white/20 to-transparent"></div>
                        </div>

                        {{-- Текст --}}
                        <div class="w-full">
                            @if(!empty($pub['type']))
                                <p class="text-[8px] md:text-[9px] font-extrabold uppercase tracking-widest text-[#E85C24] mb-1">
                                    {{ $pub['type'] }}
                                </p>
                            @endif
                            <h4 class="text-xs md:text-sm font-bold text-[#101010] leading-snug transition-colors line-clamp-3 group-hover:text-[#E85C24]" title="{{ $pub['title'] ?? '' }}">
                                {{ $pub['title'] ?? '' }}
                            </h4>
                        </div>
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>
    @endif

</div>

                {{-- ========================================== --}}
                {{-- ПРАВАЯ КОЛОНКА: ИНФО                       --}}
                {{-- ========================================== --}}
                <div class="lg:col-span-7">
                    
                    {{-- Шапка --}}
                    <div class="hidden lg:block mb-10">
                        <span class="inline-flex items-center py-1.5 px-3.5 rounded-xl bg-orange-50 text-[#E85C24] font-extrabold text-[10px] uppercase tracking-widest mb-4 border border-orange-100/50">
                            <span class="relative flex h-2 w-2 mr-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#E85C24] opacity-50"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-[#E85C24]"></span>
                            </span>
                            Ваш наставник
                        </span>
                        <h2 class="text-3xl md:text-4xl lg:text-5xl font-black text-[#101010] mb-3 tracking-tight">
                            {{ $data['name'] }}
                        </h2>
                        <p class="text-xl text-gray-500 font-medium">{{ $data['role'] }}</p>
                    </div>

                    {{-- Премиальные факты --}}
                    @if(!empty($data['stats']))
                        <div class="flex flex-wrap gap-4 mb-10">
                            @foreach($data['stats'] as $stat)
                                <div class="relative bg-white rounded-2xl p-5 border border-gray-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_10px_30px_rgba(232,92,36,0.08)] hover:border-[#E85C24]/30 transition-all duration-300 flex-1 min-w-[140px] group overflow-hidden">
                                    <div class="absolute right-0 top-0 w-16 h-16 bg-gradient-to-br from-orange-50 to-orange-100/50 rounded-bl-full -mr-4 -mt-4 transition-transform duration-500 group-hover:scale-[2.5] opacity-60 z-0"></div>
                                    
                                    <div class="relative z-10">
                                        <div class="text-3xl lg:text-4xl font-black text-[#101010] mb-1 tracking-tighter group-hover:text-[#E85C24] transition-colors">
                                            {{ $stat['value'] }}
                                        </div>
                                        <div class="text-[10px] md:text-[11px] text-gray-500 font-extrabold uppercase tracking-widest leading-snug">
                                            {{ $stat['label'] }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Прокачанное описание (Bio) --}}
                    <style>
                        /* Премиальные стили для текста биографии */
                        .premium-bio p:first-of-type {
                            font-size: 1.25rem;
                            line-height: 1.4;
                            font-weight: 800;
                            color: #101010;
                            margin-bottom: 1.5rem;
                        }
                        @media (min-width: 768px) {
                            .premium-bio p:first-of-type {
                                font-size: 1.5rem;
                            }
                        }
                        .premium-bio p {
                            margin-bottom: 1rem;
                            line-height: 1.7;
                        }
                        .premium-bio ul {
                            list-style-type: none;
                            padding-left: 0;
                            margin-bottom: 1.5rem;
                        }
                        .premium-bio li {
                            position: relative;
                            padding-left: 2rem;
                            margin-bottom: 0.75rem;
                            color: #4b5563; /* text-gray-600 */
                        }
                        .premium-bio li::before {
                            content: '✔';
                            position: absolute;
                            left: 0;
                            top: 0.1rem;
                            color: #E85C24;
                            font-size: 1.1rem;
                            font-weight: 900;
                        }
                        .premium-bio strong {
                            color: #101010;
                            background-color: #fff7ed; /* Оранжевая подложка */
                            padding: 0.1rem 0.3rem;
                            border-radius: 0.25rem;
                            font-weight: 800;
                        }
                    </style>
                    
                    <div class="premium-bio text-gray-600 text-base md:text-lg mb-0">
                        {!! $data['bio'] ?? '' !!}
                    </div>

                </div>
            </div>
        </div>
        
    </div>
</section>