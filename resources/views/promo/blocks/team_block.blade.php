<section class="py-16 lg:py-24 bg-white relative overflow-hidden" id="team">
    
    {{-- Декоративный фоновый элемент --}}
    <div class="absolute top-0 right-0 w-1/2 h-full bg-gradient-to-b from-[var(--surface-soft)] to-transparent opacity-50 pointer-events-none rounded-bl-full -translate-y-1/2 translate-x-1/3"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- Заголовок с премиум-подачей --}}
        <div class="flex flex-col items-center mb-16 relative">
            <h2 class="text-3xl md:text-5xl font-extrabold text-[var(--text-primary)] text-center max-w-3xl tracking-tight leading-tight">
                {{ $data['title'] ?? 'Наши преподаватели' }}
            </h2>
            <div class="w-20 h-1.5 rounded-full mt-6" style="background: linear-gradient(90deg, var(--accent), #f0733b);"></div>
            
            @if(!empty($data['subtitle']))
                <p class="text-[var(--text-muted)] font-medium text-center mt-6 max-w-2xl text-lg md:text-xl leading-relaxed">
                    {{ $data['subtitle'] }}
                </p>
            @endif
        </div>

        @if(!empty($data['items']))
        {{-- Умная сетка: автоматически распределяет карточки, учитывая боковую форму --}}
        <div class="flex flex-wrap justify-center gap-6 lg:gap-8">
            
            @foreach($data['items'] as $item)
                {{-- Карточка (flex-basis: 280px гарантирует, что карточка не будет слишком узкой) --}}
                <div class="w-full flex-[1_1_280px] max-w-[380px] bg-[var(--surface-soft)] rounded-[2rem] p-8 lg:p-10 border border-[var(--border)] transition-all duration-500 hover:shadow-[0_20px_40px_rgba(232,92,36,0.08)] hover:-translate-y-2 hover:bg-white group flex flex-col items-center relative overflow-hidden">
                    
                    {{-- Декоративная светящаяся линия сверху при наведении --}}
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-[var(--accent)] to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                    
                    {{-- Аватар с эффектом свечения и стильным ободком --}}
                    <div class="w-32 h-32 mb-8 relative group/avatar">
                        {{-- Светящийся фон (свечение за карточкой) --}}
                        <div class="absolute inset-0 rounded-full blur-xl opacity-20 group-hover:opacity-60 transition-opacity duration-500 scale-110" style="background: linear-gradient(135deg, var(--accent), #fca5a5);"></div>
                        
                        @php
                            // Ищем картинку в базе Куратора по её ID
                            $media = \Awcodes\Curator\Models\Media::find($item['image']);
                        @endphp

                        @if($media)
                            {{-- Обновленный блок картинки: добавили rounded-full и стильную двойную рамку --}}
                            <div class="relative w-full h-full rounded-full p-1 bg-gradient-to-br from-[var(--accent)] to-orange-200 shadow-lg group-hover:shadow-[0_0_20px_rgba(232,92,36,0.3)] transition-all duration-300 group-hover:scale-105">
                                <div class="w-full h-full rounded-full overflow-hidden bg-white p-0.5">
                                    <img src="{{ $media->url }}" alt="{{ $item['name'] ?? 'Преподаватель' }}" class="w-full h-full object-cover rounded-full">
                                </div>
                            </div>
                        @else
                            {{-- Заглушка (тоже круглая) --}}
                            <div class="relative w-full h-full rounded-full p-1 bg-gray-200">
                                <div class="w-full h-full rounded-full bg-gray-50 flex items-center justify-center text-gray-300">
                                    <i class="fas fa-user text-4xl"></i>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Имя --}}
                    <h3 class="text-2xl font-bold text-[var(--text-primary)] mb-2 text-center leading-tight group-hover:text-[var(--accent)] transition-colors duration-300">
                        {{ $item['name'] }}
                    </h3>

                    {{-- Роль / Должность --}}
                    @if(!empty($item['role']))
                        <div class="font-black text-[10px] uppercase tracking-[0.2em] mb-6 text-center" style="color: var(--accent);">
                            {{ $item['role'] }}
                        </div>
                    @endif

                    {{-- Описание (С крутыми стилями для галочек и списков) --}}
                    @if(!empty($item['description']))
                        <div class="w-full text-[var(--text-muted)] text-sm leading-relaxed font-medium 
                                    text-left
                                    [&>p]:mb-3 [&>p:last-child]:mb-0 
                                    
                                    {{-- СТИЛИ СПИСКА: Кастомные оранжевые галочки --}}
                                    [&>ul]:list-none [&>ul]:pl-0 [&>ul]:mb-4 [&>ul]:mt-4
                                    [&>ul>li]:relative [&>ul>li]:pl-7 [&>ul>li]:mb-3
                                    [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-0.5
                                    [&>ul>li]:before:content-[''] [&>ul>li]:before:w-4 [&>ul>li]:before:h-4
                                    [&>ul>li]:before:bg-[url('data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2020%2020%22%20fill%3D%22%23E85C24%22%3E%3Cpath%20fill-rule%3D%22evenodd%22%20d%3D%22M16.707%205.293a1%201%200%20010%201.414l-8%208a1%201%200%2001-1.414%200l-4-4a1%201%200%20011.414-1.414L8%2012.586l7.293-7.293a1%201%200%20011.414%200z%22%20clip-rule%3D%22evenodd%22%2F%3E%3C%2Fsvg%3E')]
                                    [&>ul>li]:before:bg-no-repeat [&>ul>li]:before:bg-center [&>ul>li]:before:bg-contain
                                    
                                    {{-- Стили нумерации и жирного текста --}}
                                    [&>ol]:list-decimal [&>ol]:pl-5 [&>ol]:mb-4 [&>ol]:mt-4 [&>ol>li]:mb-2 [&>ol>li::marker]:text-[var(--accent)] [&>ol>li::marker]:font-bold
                                    [&>strong]:text-[var(--text-primary)] [&>strong]:font-extrabold 
                                    [&>em]:text-[var(--accent)]">
                            {!! $item['description'] !!}
                        </div>
                    @endif

                </div>
            @endforeach

        </div>
        @endif

    </div>
</section>