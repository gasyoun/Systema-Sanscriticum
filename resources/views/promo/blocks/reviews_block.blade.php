<section class="py-16 lg:py-24 bg-[#F9FAFB] overflow-hidden relative font-nunito" id="otz" 
         x-data="{ lightboxOpen: false, lightboxImage: '' }">
    
    {{-- Фоновый декоративный блик --}}
    <div class="absolute top-0 right-0 w-[40rem] h-[40rem] bg-[#E85C24]/5 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- ЗАГОЛОВОК И НАВИГАЦИЯ --}}
        <div class="relative mb-12 md:mb-16">
            <div class="max-w-3xl mx-auto text-center flex flex-col items-center">
                <h2 class="text-3xl md:text-4xl lg:text-4xl font-extrabold text-[#101010] mb-4 tracking-tight">
                    {{ $data['title'] ?? 'Отзывы учеников' }}
                </h2>
                <div class="w-20 h-1.5 bg-[#E85C24] rounded-full mx-auto"></div>
            </div>

            {{-- Кнопки (Десктоп) --}}
            <div class="hidden lg:flex gap-3 absolute right-0 bottom-0 top-0 items-center">
                <button @click="$refs.slider.scrollBy({left: -380, behavior: 'smooth'})"
                        class="w-12 h-12 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-[#E85C24] hover:border-[#E85C24] hover:text-white hover:shadow-[0_4px_12px_rgba(232,92,36,0.3)] transition-all bg-white shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                </button>
                <button @click="$refs.slider.scrollBy({left: 380, behavior: 'smooth'})"
                        class="w-12 h-12 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-[#E85C24] hover:border-[#E85C24] hover:text-white hover:shadow-[0_4px_12px_rgba(232,92,36,0.3)] transition-all bg-white shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>
        </div>

        {{-- СЛАЙДЕР С КАРТОЧКАМИ --}}
        @if(!empty($data['reviews']))
        <div class="relative">
            <div class="absolute top-0 left-0 w-12 h-full bg-gradient-to-r from-[#F9FAFB] to-transparent z-20 pointer-events-none md:hidden"></div>
            <div class="absolute top-0 right-0 w-12 h-full bg-gradient-to-l from-[#F9FAFB] to-transparent z-20 pointer-events-none md:hidden"></div>

            {{-- ДОБАВЛЕН КЛАСС items-start СЮДА --}}
            <div x-ref="slider" class="flex items-start gap-5 md:gap-6 overflow-x-auto snap-x snap-mandatory pb-12 pt-10 hide-scrollbar -mx-4 px-4 md:mx-0 md:px-0">
                
                @foreach($data['reviews'] as $review)
                    <div class="flex-shrink-0 w-[85vw] sm:w-[320px] md:w-[360px] snap-center">
                        
                        {{-- Убрал класс h-full отсюда --}}
                        <div class="relative bg-white rounded-3xl p-6 md:p-8 shadow-[0_8px_30px_rgba(0,0,0,0.04)] border border-gray-100 flex flex-col mt-6 hover:shadow-[0_15px_40px_rgba(232,92,36,0.08)] hover:border-[#E85C24]/20 transition-all duration-300 group">
                            
                            {{-- Выступающий Аватар --}}
                            <div class="absolute -top-8 left-6 w-16 h-16 rounded-full border-4 border-white shadow-md bg-gray-100 overflow-hidden z-20">
                                @if(!empty($review['avatar']))
                                    <img src="{{ Storage::url($review['avatar']) }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-orange-100 to-orange-50 text-[#E85C24] font-black text-xl">
                                        {{ mb_substr($review['name'], 0, 1) }}
                                    </div>
                                @endif
                            </div>

                            {{-- Декоративная кавычка --}}
                            <div class="absolute top-4 right-5 text-orange-50 group-hover:text-orange-100 transition-colors duration-500 z-0">
                                <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                            </div>

                            {{-- Имя, Дата, Звезды --}}
                            <div class="pt-6 mb-5 flex justify-between items-start relative z-10">
                                <div>
                                    <h4 class="font-extrabold text-[#101010] text-lg leading-tight">{{ $review['name'] }}</h4>
                                    <div class="flex items-center gap-3 mt-1.5">
                                        <div class="flex text-[#FFB800] gap-0.5">
                                            @for($i=0; $i<5; $i++)
                                                <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                                            @endfor
                                        </div>
                                        @if(!empty($review['date']))
                                            <span class="text-[10px] font-extrabold text-gray-300 uppercase tracking-widest">{{ $review['date'] }}</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Кнопка соцсети --}}
                                @if(!empty($review['contact_link']))
                                    <a href="{{ $review['contact_link'] }}" target="_blank" rel="nofollow noopener" 
                                       class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-50 text-gray-400 border border-gray-100 hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] transition-colors"
                                       title="Связаться">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>
                                @endif
                            </div>

                            {{-- ТЕКСТ (УМНОЕ РАСКРЫТИЕ ПО КЛИКУ) --}}
                            <div class="relative mb-6 flex-grow" 
                                 x-data="{ expanded: false, showButton: false }" 
                                 x-init="$nextTick(() => { showButton = $refs.textNode.scrollHeight > $refs.textNode.clientHeight })">
                                
                                <div class="text-gray-600 text-sm leading-relaxed overflow-hidden transition-all duration-300"
                                     :class="expanded ? '' : 'line-clamp-4'"
                                     x-ref="textNode">
                                    {{ $review['text'] }}
                                </div>
                                
                                {{-- Градиент, скрывающий оборванный текст --}}
                                <div x-show="!expanded && showButton" class="absolute bottom-5 left-0 w-full h-8 bg-gradient-to-t from-white to-transparent pointer-events-none"></div>

                                {{-- Кнопка появится только если текста больше 4 строк --}}
                                <button x-show="showButton" 
                                        @click="expanded = !expanded"
                                        class="text-[#E85C24] text-[11px] font-extrabold uppercase tracking-widest mt-2 hover:text-[#d6501f] transition-colors focus:outline-none">
                                    <span x-text="expanded ? 'Свернуть' : 'Читать полностью'"></span>
                                </button>
                            </div>

                            {{-- СКРИНШОТЫ --}}
                            @if(!empty($review['images']))
                                <div class="flex gap-2 overflow-x-auto pb-1 mt-auto hide-scrollbar">
                                    @foreach($review['images'] as $image)
                                        <div class="shrink-0 cursor-zoom-in group/img"
                                             @click="lightboxOpen = true; lightboxImage = '{{ Storage::url($image) }}'">
                                            <div class="w-16 h-16 rounded-xl overflow-hidden border border-gray-100 relative">
                                                <img src="{{ Storage::url($image) }}" class="w-full h-full object-cover group-hover/img:scale-110 transition-transform duration-500">
                                                <div class="absolute inset-0 bg-black/20 opacity-0 group-hover/img:opacity-100 transition-opacity flex items-center justify-center">
                                                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" /></svg>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                        </div>
                    </div>
                @endforeach

            </div>
        </div>
        @endif
    </div>

    {{-- МОДАЛЬНОЕ ОКНО ДЛЯ СКРИНШОТОВ --}}
    <div x-show="lightboxOpen" 
         x-transition.opacity.duration.300ms
         class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#101010]/95 p-4 backdrop-blur-md"
         style="display: none;"
         @keydown.escape.window="lightboxOpen = false">
        
        <button @click="lightboxOpen = false" class="absolute top-6 right-6 w-12 h-12 bg-white/10 hover:bg-[#E85C24] text-white rounded-full flex items-center justify-center transition-colors z-10">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>

        <div @click.outside="lightboxOpen = false" class="relative max-w-5xl max-h-full">
            <img :src="lightboxImage" class="max-w-full max-h-[90vh] rounded-2xl shadow-2xl object-contain">
        </div>
    </div>

    <style>
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</section>