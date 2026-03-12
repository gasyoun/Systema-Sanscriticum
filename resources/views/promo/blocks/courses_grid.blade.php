@php
    $title = $block['data']['title'] ?? 'Наши курсы';
    $subtitle = $block['data']['subtitle'] ?? 'Выберите курс для начала обучения.';
    $perPage = $block['data']['limit'] ?? 6;

    // Начинаем собирать запрос
    $query = \App\Models\LandingPage::where('is_active', true);

    // Исключаем текущий лендинг
    if (isset($page)) {
        $query->where('id', '!=', $page->id);
    }

    $landings = $query->orderBy('created_at', 'desc')->take(24)->get();
@endphp

<div class="py-12 md:py-20 bg-white relative font-nunito" 
     x-data="{ 
         page: 1, 
         perPage: {{ $perPage }}, 
         total: {{ $landings->count() }},
         get totalPages() { return Math.ceil(this.total / this.perPage); },
         nextPage() { if (this.page < this.totalPages) this.page++; },
         prevPage() { if (this.page > 1) this.page--; }
     }">
     
    <div class="container mx-auto px-4 relative z-10">

        {{-- ЗАГОЛОВОК (Центрированный - Исправленный) --}}
        <div class="max-w-3xl mx-auto text-center mb-12 md:mb-16">
            @if($title)
                <h2 class="text-2xl md:text-3xl lg:text-4xl font-extrabold text-[#101010] mb-4 tracking-tight">
                    {{ $title }}
                </h2>
            @endif
            
            @if($subtitle)
                <p class="text-base md:text-lg text-gray-500 leading-relaxed mb-6">
                    {{ $subtitle }}
                </p>
            @endif
            
            <div class="w-20 h-1.5 bg-[#E85C24] mx-auto rounded-full"></div>
        </div>

        @if($landings->count() > 0)
            
            {{-- СЕТКА КУРСОВ --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
                
                @foreach($landings as $index => $landing)
                    <div x-show="Math.ceil(({{ $index }} + 1) / perPage) === page"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="w-full h-full"
                         style="display: none;" 
                         x-init="$el.style.display = 'block'">
                        
                        <a href="{{ url('/' . $landing->slug) }}" class="group block h-full relative transition-transform duration-300 hover:-translate-y-1">
    
    {{-- Плашка (Бейдж) - ТЕПЕРЬ СНАРУЖИ БЛОКА С overflow-hidden --}}
    @if($landing->webinar_label)
        {{-- -left-4 вытаскивает её на 16px влево, -left-5 вытащит на 20px --}}
        <span class="absolute top-6 -left-3 z-30 bg-[#E85C24] text-white text-[9px] sm:text-[10px] font-extrabold uppercase tracking-widest px-3 py-1.5 rounded-lg shadow-[0_4px_12px_rgba(232,92,36,0.4)]">
            {{ $landing->webinar_label }}
        </span>
    @endif

    {{-- Сама Карточка --}}
    <div class="flex flex-col h-full w-full bg-white border border-gray-100 rounded-[1.5rem] md:rounded-[2rem] overflow-hidden transition-all duration-300 group-hover:border-[#E85C24]/30 group-hover:shadow-[0_20px_40px_rgba(0,0,0,0.06)]">
        
        {{-- Блок изображения --}}
        <div class="relative w-full aspect-[4/5] bg-gray-50 overflow-hidden shrink-0">
            @if($landing->image_path ?? $landing->showcase_image)
                <img src="{{ asset('storage/' . ($landing->image_path ?? $landing->showcase_image)) }}" alt="{{ $landing->title }}" class="absolute inset-0 w-full h-full object-cover object-top group-hover:scale-105 transition-transform duration-700 ease-in-out">
            @else
                <div class="absolute inset-0 flex items-center justify-center text-gray-300 bg-gray-100">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
            @endif
            <div class="absolute inset-0 bg-gradient-to-b from-black/30 via-transparent to-transparent z-10"></div>
        </div>

        {{-- Блок контента --}}
        <div class="p-6 md:p-8 flex flex-col flex-grow bg-white z-10">
            
            @if($landing->instructor_name)
                <p class="text-[#E85C24] text-[10px] font-extrabold uppercase tracking-widest mb-2.5">
                    {{ $landing->instructor_name }}
                </p>
            @endif
            
            <h3 class="text-xl font-extrabold text-[#101010] mb-3 group-hover:text-[#E85C24] transition-colors leading-snug line-clamp-2">
                {{ $landing->title }}
            </h3>
            
            @if($landing->webinar_date)
                <div class="flex items-center text-xs text-gray-500 mb-4 bg-gray-50 self-start px-3 py-1.5 rounded-lg border border-gray-100">
                    <i class="far fa-calendar-alt mr-2 text-[#E85C24]"></i>
                    Старт: <span class="font-bold text-gray-800 ml-1">{{ \Carbon\Carbon::parse($landing->webinar_date)->translatedFormat('d F Y') }}</span>
                </div>
            @endif

            <p class="text-gray-500 text-sm leading-relaxed line-clamp-3 mb-6 flex-grow">
                {{ $landing->description ?? $landing->showcase_description ?? 'Узнать подробнее о программе курса, расписании и стоимости.' }}
            </p>

            <div class="mt-auto pt-5 border-t border-gray-50 flex items-center justify-between">
                <span class="inline-flex items-center text-sm font-extrabold uppercase tracking-widest text-[#101010] group-hover:text-[#E85C24] transition-colors">
                    {{ $landing->button_text ?? 'Подробнее' }}
                </span>
                <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-[#E85C24] transition-all duration-300">
                    <svg class="w-4 h-4 text-gray-400 group-hover:text-white group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
                </div>
            </div>

        </div>

    </div>
</a>
                    </div>
                @endforeach
            </div>

            {{-- НАВИГАЦИЯ (ПАГИНАЦИЯ) - Теперь выровнена по центру --}}
            <div x-show="totalPages > 1" class="flex justify-center items-center mt-12 gap-2" x-cloak style="display: none;" x-init="$el.style.display = 'flex'">
                <button @click="prevPage" 
                        :disabled="page === 1"
                        :class="page === 1 ? 'opacity-50 cursor-not-allowed text-gray-400 border-gray-200' : 'text-gray-700 hover:text-white hover:bg-[#101010] hover:border-[#101010] border-gray-300'"
                        class="w-10 h-10 flex items-center justify-center rounded-full border transition-all bg-white shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                </button>

                <div class="flex gap-2">
                    <template x-for="p in totalPages" :key="p">
                        <button @click="page = p"
                                :class="page === p ? 'bg-[#E85C24] text-white border-[#E85C24] shadow-[0_4px_10px_rgba(232,92,36,0.3)]' : 'bg-white text-gray-700 border-gray-300 hover:bg-[#101010] hover:border-[#101010] hover:text-white'"
                                class="w-10 h-10 flex items-center justify-center rounded-full border font-extrabold text-xs transition-all shadow-sm"
                                x-text="p">
                        </button>
                    </template>
                </div>

                <button @click="nextPage" 
                        :disabled="page === totalPages"
                        :class="page === totalPages ? 'opacity-50 cursor-not-allowed text-gray-400 border-gray-200' : 'text-gray-700 hover:text-white hover:bg-[#101010] hover:border-[#101010] border-gray-300'"
                        class="w-10 h-10 flex items-center justify-center rounded-full border transition-all bg-white shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
                </button>
            </div>

        @else
            <div class="text-center py-12">
                <p class="text-gray-500 text-lg">Скоро здесь появятся наши новые курсы!</p>
            </div>
        @endif

    </div>
</div>