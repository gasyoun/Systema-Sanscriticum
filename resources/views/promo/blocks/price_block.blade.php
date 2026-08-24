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
            <div class="w-20 h-1.5 bg-brand rounded-full mb-6"></div>
            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-lg leading-relaxed">{{ $data['subtitle'] }}</p>
            @endif
        </div>

        {{-- ========================================== --}}
        {{-- БЛОК ДЕФИЦИТА (только реальные данные, D4) --}}
        {{-- ========================================== --}}
        @php
            // Честный дефицит: рендерятся только настроенные менеджером данные.
            // Нет реальной даты — нет дедлайна; нет реальных чисел — нет счетчика мест.
            // Пустая конфигурация деградирует до честного прайса, а не до фальшивого.
            $deadline = null;
            if (!empty($data['timer_end'])) {
                $deadline = \Illuminate\Support\Carbon::parse($data['timer_end']);
                if ($deadline->isPast()) {
                    $deadline = null; // истекший дедлайн — тоже фальшивка, не показываем
                }
            }

            $seatsTaken = $data['seats_taken'] ?? null;
            $seatsTotal = $data['seats_total'] ?? null;
            $showSeats = is_numeric($seatsTaken) && is_numeric($seatsTotal)
                && (int) $seatsTotal > 0
                && (int) $seatsTaken >= 0
                && (int) $seatsTaken <= (int) $seatsTotal;
            if ($showSeats) {
                $seatsTaken = (int) $seatsTaken;
                $seatsTotal = (int) $seatsTotal;
                $seatsPercent = ($seatsTaken / $seatsTotal) * 100;
            }
        @endphp

        @if($deadline || $showSeats)
        <div class="max-w-4xl mx-auto mb-16 bg-white rounded-3xl p-1.5 shadow-[0_10px_40px_rgba(232,92,36,0.1)] border border-orange-100 relative overflow-hidden">
            <div class="bg-orange-50/50 rounded-[1.25rem] p-6 md:p-8 flex flex-col md:flex-row gap-8 md:gap-12 items-center justify-between relative z-10 border border-white/50">

                @if($deadline)
                {{-- 1. Дедлайн цены: дата и что изменится, без обратного отсчета --}}
                <div class="w-full md:w-1/2 text-center md:text-left">
                    <div class="text-[11px] font-extrabold text-gray-500 uppercase tracking-widest mb-3 flex items-center justify-center md:justify-start gap-2">
                        <svg class="w-4 h-4 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Текущая цена действует до
                    </div>
                    <div class="text-3xl font-black text-[#101010] leading-none">
                        {{ $deadline->format('H:i') === '00:00' ? $deadline->translatedFormat('d F') : $deadline->translatedFormat('d F, H:i') }}
                    </div>
                    <div class="text-sm text-gray-500 mt-3">После этой даты стоимость будет выше.</div>
                </div>
                @endif

                @if($deadline && $showSeats)
                {{-- Разделитель --}}
                <div class="hidden md:block w-px h-20 bg-gradient-to-b from-transparent via-orange-200 to-transparent"></div>
                @endif

                @if($showSeats)
                {{-- 2. Места: реальные числа набора из настроек блока --}}
                <div class="w-full md:w-1/2 max-w-sm">
                    <div class="mb-3">
                        <div class="text-[11px] font-extrabold text-gray-500 uppercase tracking-widest mb-1">Свободных мест:</div>
                        <div class="text-3xl font-black text-[#101010] leading-none">{{ $seatsTotal - $seatsTaken }} <span class="text-sm text-gray-400 font-bold">из {{ $seatsTotal }}</span></div>
                    </div>
                    <div class="w-full bg-white rounded-full h-3.5 border border-orange-100 overflow-hidden shadow-inner p-0.5">
                        <div class="h-full rounded-full bg-brand transition-all duration-1000 ease-out" style="width: {{ $seatsPercent }}%"></div>
                    </div>
                </div>
                @endif

            </div>
        </div>
        @endif

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
                                    ? 'shadow-[0_20px_50px_rgba(232,92,36,0.15)] ring-2 ring-brand md:-translate-y-4 z-10' 
                                    : 'shadow-lg hover:shadow-2xl border border-gray-100 hover:-translate-y-2' 
                                }}">
                        
                        @if($isPopular)
                            {{-- Премиальная плашка Хит Продаж --}}
                            <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-gradient-to-r from-brand to-orange-500 text-white text-[10px] font-black uppercase tracking-widest py-2 px-6 rounded-full shadow-[0_5px_15px_rgba(232,92,36,0.4)] z-20 flex items-center gap-1.5">
                                <svg class="w-3 h-3 animate-pulse" fill="currentColor" viewBox="0 0 20 20"><path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/></svg>
                                Хит продаж
                            </div>
                        @endif

                        {{-- Внутренний паддинг карточки --}}
                        <div class="p-8 lg:p-10 flex flex-col h-full relative z-10">
                            
                            {{-- Шапка тарифа --}}
                            <div class="text-center mb-8 border-b border-gray-100 pb-8">
                                <h3 class="text-xl font-black text-[#101010] uppercase tracking-wider mb-4 {{ $isPopular ? 'text-brand' : '' }}">
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
                                {!! \App\Support\RichHtml::sanitize($item['features'] ?? '') !!}
                            </div>

                            {{-- Кнопка --}}
                            <div class="mt-auto">
                                <button @click.prevent="$dispatch('open-order-form')"
        class="block w-full py-4 rounded-xl font-extrabold text-sm uppercase tracking-widest text-center transition-all duration-300 
               {{ $isPopular 
                  ? 'bg-brand text-white hover:bg-brand-hover shadow-[0_8px_20px_rgba(232,92,36,0.3)] hover:-translate-y-0.5' 
                  : 'bg-gray-100 text-gray-900 hover:bg-gray-200 hover:-translate-y-0.5' 
               }}">
    {{ $item['button_text'] ?? 'Записаться на курс' }}
</button>
                            </div>

                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>

</section>