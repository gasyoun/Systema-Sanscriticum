<section class="py-16 lg:py-24 bg-[#F9FAFB] relative font-nunito overflow-hidden" id="price">
    
    {{-- Легкий фоновый декор --}}
    <div class="absolute top-1/4 left-0 w-96 h-96 bg-orange-100/50 rounded-full blur-[100px] -translate-x-1/2 pointer-events-none z-0"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- ========================================== --}}
        {{-- ЗАГОЛОВОК (Синхронизирован с остальными)   --}}
        {{-- ========================================== --}}
        <div class="text-center mb-12 max-w-3xl mx-auto flex flex-col items-center">
            <h2 class="text-3xl md:text-4xl lg:text-4xl font-extrabold text-[#101010] mb-5 tracking-tight">
                {{ $data['title'] ?? 'Стоимость участия' }}
            </h2>
            <div class="w-20 h-1.5 bg-[#E85C24] rounded-full mb-6"></div>
            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-lg leading-relaxed">{{ $data['subtitle'] }}</p>
            @endif
        </div>

        {{-- ========================================== --}}
        {{-- БЛОК ДЕФИЦИТА (Агрессивный маркетинг)      --}}
        {{-- ========================================== --}}
        <div class="max-w-4xl mx-auto mb-16 bg-white rounded-3xl p-1.5 shadow-[0_10px_40px_rgba(232,92,36,0.1)] border border-orange-100 relative group overflow-hidden">
            
            {{-- Мигающий индикатор "Live" в углу --}}
            <div class="absolute top-4 right-4 flex items-center gap-2 z-20">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#E85C24] opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-[#E85C24]"></span>
                </span>
                <span class="text-[10px] font-black uppercase tracking-widest text-[#E85C24]">Акция</span>
            </div>

            <div class="bg-orange-50/50 rounded-[1.25rem] p-6 md:p-8 flex flex-col md:flex-row gap-8 md:gap-12 items-center justify-between relative z-10 border border-white/50">
                
                {{-- 1. Таймер --}}
                @php
                    $timerDate = $data['timer_end'] ?? null;
                    if(!$timerDate && isset($page) && $page->webinar_date) {
                        $timerDate = $page->webinar_date->format('Y-m-d H:i:s');
                    }
                    if(!$timerDate) {
                        $timerDate = now()->addHours(24)->format('Y-m-d H:i:s');
                    }
                @endphp

                <div x-data="timer('{{ $timerDate }}')" x-init="init()" class="w-full md:w-1/2">
                    <div class="text-[11px] font-extrabold text-gray-500 uppercase tracking-widest mb-3 flex items-center justify-center md:justify-start gap-2">
                        <svg class="w-4 h-4 text-[#E85C24] animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Повышение цены через:
                    </div>
                    
                    <div class="flex gap-2 sm:gap-3 justify-center md:justify-start">
                        @foreach(['days' => 'Дн', 'hours' => 'Час', 'minutes' => 'Мин', 'seconds' => 'Сек'] as $var => $label)
                            <div class="flex flex-col items-center">
                                <div class="bg-white rounded-xl shadow-[0_4px_15px_rgba(0,0,0,0.05)] border-b-2 border-[#E85C24]/20 w-12 h-14 sm:w-14 sm:h-16 flex items-center justify-center text-xl sm:text-2xl font-black text-[#101010] font-mono relative overflow-hidden group-hover:border-[#E85C24] transition-colors">
                                    {{-- Блик на карточке --}}
                                    <div class="absolute inset-0 bg-gradient-to-b from-white/60 to-transparent"></div>
                                    <span x-text="{{ $var }}" class="relative z-10 text-shadow-sm">00</span>
                                </div>
                                <div class="text-[9px] sm:text-[10px] text-gray-400 mt-2 uppercase font-extrabold tracking-widest">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Разделитель --}}
                <div class="hidden md:block w-px h-20 bg-gradient-to-b from-transparent via-orange-200 to-transparent"></div>

                {{-- 2. Анимированный счетчик мест --}}
                @php
                    $taken = $data['seats_taken'] ?? 16;
                    $total = $data['seats_total'] ?? 20;
                    $percent = ($total > 0) ? ($taken / $total) * 100 : 80;
                @endphp
                <div class="w-full md:w-1/2 max-w-sm">
                    <div class="flex items-end justify-between mb-3">
                        <div>
                            <div class="text-[11px] font-extrabold text-gray-500 uppercase tracking-widest mb-1">Доступно мест:</div>
                            <div class="text-3xl font-black text-[#101010] leading-none">{{ $total - $taken }} <span class="text-sm text-gray-400 font-bold">из {{ $total }}</span></div>
                        </div>
                        <div class="text-[#E85C24] font-bold text-sm bg-orange-100/50 px-2 py-1 rounded-md animate-pulse">Осталось мало!</div>
                    </div>
                    
                    {{-- Прогресс-бар с CSS-анимацией полосок --}}
                    <div class="w-full bg-white rounded-full h-3.5 mb-2 border border-orange-100 overflow-hidden shadow-inner p-0.5">
                        <div class="h-full rounded-full relative overflow-hidden transition-all duration-1000 ease-out bg-[#E85C24]" style="width: {{ $percent }}%">
                            {{-- Анимация движущихся полосок --}}
                            <div class="absolute inset-0 bg-[linear-gradient(45deg,rgba(255,255,255,.2)_25%,transparent_25%,transparent_50%,rgba(255,255,255,.2)_50%,rgba(255,255,255,.2)_75%,transparent_75%,transparent)] bg-[length:1rem_1rem] animate-[progress_1s_linear_infinite]"></div>
                        </div>
                    </div>
                    <div class="text-[10px] text-gray-400 text-right font-extrabold uppercase tracking-widest">
                        Забронировано {{ $taken }} мест
                    </div>
                </div>

            </div>
        </div>

        {{-- ========================================== --}}
        {{-- ТАРИФЫ --}}
        {{-- ========================================== --}}
        @if(!empty($data['tariffs']))
            @php
                $count = count($data['tariffs']);
                $gridClass = match ($count) {
                    1 => 'md:grid-cols-1 max-w-md',
                    2 => 'md:grid-cols-2 max-w-5xl',
                    default => 'md:grid-cols-3 max-w-7xl'
                };
            @endphp

            <div class="grid grid-cols-1 {{ $gridClass }} gap-6 lg:gap-8 mx-auto items-stretch">
                @foreach($data['tariffs'] as $item)
                    @php
                        $isPopular = $item['is_popular'] ?? false;
                    @endphp

                    <div class="relative flex flex-col bg-white rounded-[2rem] transition-all duration-300 h-full
                                {{ $isPopular 
                                    ? 'shadow-[0_20px_50px_rgba(232,92,36,0.15)] ring-2 ring-[#E85C24] md:-translate-y-4 z-10' 
                                    : 'shadow-lg hover:shadow-2xl border border-gray-100 hover:-translate-y-2' 
                                }}">
                        
                        @if($isPopular)
                            {{-- Премиальная плашка Хит Продаж --}}
                            <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-gradient-to-r from-[#E85C24] to-orange-500 text-white text-[10px] font-black uppercase tracking-widest py-2 px-6 rounded-full shadow-[0_5px_15px_rgba(232,92,36,0.4)] z-20 flex items-center gap-1.5">
                                <svg class="w-3 h-3 animate-pulse" fill="currentColor" viewBox="0 0 20 20"><path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/></svg>
                                Хит продаж
                            </div>
                        @endif

                        {{-- Внутренний паддинг карточки --}}
                        <div class="p-8 lg:p-10 flex flex-col h-full relative z-10">
                            
                            {{-- Шапка тарифа --}}
                            <div class="text-center mb-8 border-b border-gray-100 pb-8">
                                <h3 class="text-xl font-black text-[#101010] uppercase tracking-wider mb-4 {{ $isPopular ? 'text-[#E85C24]' : '' }}">
                                    {{ $item['name'] }}
                                </h3>
                                
                                <div class="flex flex-col items-center justify-center">
                                    @if(!empty($item['old_price']))
                                        <span class="text-sm font-bold text-gray-400 line-through decoration-red-500/50 mb-1">
                                            {{ $item['old_price'] }}
                                        </span>
                                    @endif
                                    <div class="flex items-start justify-center gap-1">
                                        <span class="text-4xl lg:text-5xl font-black text-[#101010] tracking-tighter">
                                            {{ $item['price'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Список фичей --}}
                            <div class="mb-10 flex-grow text-gray-600 font-medium text-sm md:text-base
                                        [&>ul]:list-none [&>ul]:p-0 [&>ul]:m-0 [&>ul]:divide-y [&>ul]:divide-gray-50
                                        [&>ul>li]:relative [&>ul>li]:pl-8 [&>ul>li]:py-3 
                                        [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-3.5 
                                        [&>ul>li]:before:w-5 [&>ul>li]:before:h-5 [&>ul>li]:before:bg-[url('data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23E85C24%22%20stroke-width%3D%223%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%2220%206%209%2017%204%2012%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E')] [&>ul>li]:before:bg-no-repeat [&>ul>li]:before:bg-center">
                                {!! $item['features'] ?? '' !!}
                            </div>

                            {{-- Кнопка --}}
                            <div class="mt-auto">
                                <a href="#order-form-anchor" 
                                   class="block w-full py-4 rounded-xl font-extrabold text-sm uppercase tracking-widest text-center transition-all duration-300 
                                          {{ $isPopular 
                                             ? 'bg-[#E85C24] text-white hover:bg-[#d04a15] shadow-[0_8px_20px_rgba(232,92,36,0.3)] hover:-translate-y-0.5' 
                                             : 'bg-gray-100 text-gray-900 hover:bg-gray-200 hover:-translate-y-0.5' 
                                          }}">
                                    {{ $item['button_text'] ?? 'Записаться на курс' }}
                                </a>
                            </div>

                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>

    {{-- Стили для анимации бегущей строки (Progress Bar) --}}
    <style>
        @keyframes progress {
            0% { background-position: 1rem 0; }
            100% { background-position: 0 0; }
        }
    </style>

    {{-- Скрипт таймера --}}
    <script>
        function timer(expiry) {
            return {
                expiry: expiry,
                remaining: null,
                days: '00', hours: '00', minutes: '00', seconds: '00',
                init() {
                    this.setRemaining();
                    setInterval(() => { this.setRemaining(); }, 1000);
                },
                setRemaining() {
                    const diff = Date.parse(this.expiry) - new Date().getTime();
                    if (diff >= 0) {
                        this.days = this.format(Math.floor(diff / (1000 * 60 * 60 * 24)));
                        this.hours = this.format(Math.floor((diff / (1000 * 60 * 60)) % 24));
                        this.minutes = this.format(Math.floor((diff / 1000 / 60) % 60));
                        this.seconds = this.format(Math.floor((diff / 1000) % 60));
                    } else {
                        this.days = '00'; this.hours = '00'; this.minutes = '00'; this.seconds = '00';
                    }
                },
                format(value) { return ("0" + value).slice(-2); }
            }
        }
    </script>
</section>