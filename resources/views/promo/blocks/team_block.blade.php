<section class="py-10 lg:py-16 bg-white" id="team">
    <div class="container mx-auto px-4">
        
        {{-- Заголовок с полосой --}}
        <div class="flex flex-col items-center mb-12">
            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] text-center max-w-3xl">
                {{ $data['title'] ?? 'Наши преподаватели' }}
            </h2>
            <div class="w-24 h-1.5 bg-[#E85C24] rounded-full mt-4"></div>
            
            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-center mt-4 max-w-2xl text-lg">
                    {{ $data['subtitle'] }}
                </p>
            @endif
        </div>

        @if(!empty($data['items']))
        {{-- Сетка: 1 колонка на моб, 2 на планшете, 4 на десктопе --}}
        <div class="flex flex-wrap justify-center gap-6 lg:gap-8">
            
            @foreach($data['items'] as $item)
                {{-- Карточка (добавлена фиксированная ширина для центровки flex) --}}
                <div class="w-full md:w-[48%] lg:w-[23%] bg-[#F9FAFB] rounded-[2rem] p-6 text-center border border-gray-100 transition-all duration-300 hover:shadow-xl hover:-translate-y-2 hover:bg-white group h-full flex flex-col items-center">
                    
                    {{-- Фото --}}
                    <div class="w-32 h-32 mb-6 relative">
                        <div class="absolute inset-0 bg-orange-100 rounded-full scale-95 group-hover:scale-110 transition-transform duration-500"></div>
                        @if(!empty($item['image']))
                            <img src="{{ Storage::url($item['image']) }}" 
                                 class="w-full h-full object-cover rounded-full border-4 border-white shadow-sm relative z-10" 
                                 alt="{{ $item['name'] }}">
                        @else
                            {{-- Заглушка, если нет фото --}}
                            <div class="w-full h-full rounded-full border-4 border-white shadow-sm bg-gray-200 flex items-center justify-center relative z-10 text-gray-400">
                                <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            </div>
                        @endif
                    </div>

                    {{-- Имя --}}
                    <h3 class="text-xl font-bold text-[#101010] mb-2 leading-tight">
                        {{ $item['name'] }}
                    </h3>

                    {{-- Роль / Должность --}}
                    @if(!empty($item['role']))
                        <div class="text-[#E85C24] font-bold text-xs uppercase tracking-widest mb-4">
                            {{ $item['role'] }}
                        </div>
                    @endif

                    {{-- Описание (С поддержкой форматирования и ГАЛОЧКАМИ) --}}
                    @if(!empty($item['description']))
                        <div class="text-gray-500 text-sm leading-relaxed font-medium 
                                    text-left
                                    [&>p]:mb-2 [&>p:last-child]:mb-0 
                                    
                                    {{-- СТИЛИ СПИСКА: Галочки вместо точек --}}
                                    [&>ul]:list-none [&>ul]:pl-0 [&>ul]:mb-2 
                                    [&>ul>li]:relative [&>ul>li]:pl-6 [&>ul>li]:mb-2
                                    [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-0
                                    [&>ul>li]:before:content-['✔'] [&>ul>li]:before:text-green-500 [&>ul>li]:before:font-bold
                                    
                                    {{-- Стили нумерации и жирного текста --}}
                                    [&>ol]:list-decimal [&>ol]:pl-5 [&>ol]:mb-2 
                                    [&>strong]:text-gray-900 [&>strong]:font-bold 
                                    [&>em]:text-orange-500">
                            {!! $item['description'] !!}
                        </div>
                    @endif

                </div>
            @endforeach

        </div>
        @endif

    </div>
</section>