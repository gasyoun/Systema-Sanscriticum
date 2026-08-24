@php
    // Берём только корректно настроенные потоки (с привязанным тарифом),
    // чтобы битая конфигурация не уронила route().
    $streams = collect($data['streams'] ?? [])->filter(fn ($s) => ! empty($s['tariff_id']))->values();
@endphp

@if($streams->isNotEmpty())
<section class="py-16 lg:py-24 bg-[#F9FAFB] relative font-nunito overflow-hidden" id="streams">

    {{-- Лёгкий фоновый декор --}}
    <div class="absolute top-1/4 right-0 w-96 h-96 bg-orange-100/50 rounded-full blur-[100px] translate-x-1/2 pointer-events-none z-0"></div>

    <div class="container mx-auto px-4 relative z-10">

        {{-- Заголовок --}}
        <div class="text-center mb-12 max-w-3xl mx-auto flex flex-col items-center">
            <h2 class="text-3xl md:text-4xl lg:text-4xl font-extrabold text-[#101010] mb-5 tracking-tight">
                {{ $data['title'] ?? 'Выберите удобный поток' }}
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
            // Пустая конфигурация деградирует до честного списка потоков, а не до фальшивки.
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

        {{-- Карточки потоков --}}
        @php
            $count = $streams->count();
            $gridClass = match (true) {
                $count === 1 => 'sm:grid-cols-1 max-w-sm',
                $count === 2 => 'sm:grid-cols-2 max-w-3xl',
                $count === 3 => 'sm:grid-cols-2 lg:grid-cols-3 max-w-6xl',
                $count === 4 => 'sm:grid-cols-2 lg:grid-cols-4 max-w-7xl',
                default => 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 max-w-[1600px]',
            };
        @endphp

        <div class="grid grid-cols-1 {{ $gridClass }} gap-4 lg:gap-5 mx-auto items-stretch">
            @foreach($streams as $item)
                @php
                    $isPopular = $item['is_popular'] ?? false;
                    $href = route('checkout.show', $item['tariff_id']);
                    if (!empty($item['promo_code'])) {
                        $href .= '?promo=' . urlencode($item['promo_code']);
                    }
                @endphp

                <div class="relative flex flex-col bg-white rounded-3xl transition-all duration-300 h-full
                            {{ $isPopular
                                ? 'shadow-[0_20px_50px_rgba(232,92,36,0.15)] ring-2 ring-brand lg:-translate-y-2 z-10'
                                : 'shadow-lg hover:shadow-2xl border border-gray-100 hover:-translate-y-1'
                            }}">

                    @if($isPopular)
                        <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-gradient-to-r from-brand to-orange-500 text-white text-[10px] font-black uppercase tracking-widest py-2 px-6 rounded-full shadow-[0_5px_15px_rgba(232,92,36,0.4)] z-20 flex items-center gap-1.5">
                            <svg class="w-3 h-3 animate-pulse" fill="currentColor" viewBox="0 0 20 20"><path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/></svg>
                            Популярный
                        </div>
                    @endif

                    <div class="p-5 lg:p-6 flex flex-col h-full relative z-10">

                        {{-- Шапка потока --}}
                        <div class="text-center mb-5 border-b border-gray-100 pb-5">
                            <h3 class="text-base lg:text-lg font-black text-[#101010] uppercase tracking-wider mb-3 {{ $isPopular ? 'text-brand' : '' }}">
                                {{ $item['name'] ?? '' }}
                            </h3>

                            {{-- Расписание — ключевой акцент --}}
                            <div class="inline-flex items-center gap-1.5 bg-orange-50 text-brand font-extrabold text-sm px-3.5 py-2 rounded-xl border border-orange-100">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>{{ $item['schedule'] ?? '' }}</span>
                            </div>

                            @if(!empty($item['start_note']))
                                <div class="text-xs text-gray-400 font-bold mt-2">{{ $item['start_note'] }}</div>
                            @endif

                            @if(!empty($item['price']) || !empty($item['old_price']))
                                <div class="flex flex-col items-center justify-center mt-4">
                                    @if(!empty($item['old_price']))
                                        <span class="text-xs font-bold text-gray-400 line-through decoration-red-500/50 mb-0.5">
                                            {{ $item['old_price'] }}
                                        </span>
                                    @endif
                                    @if(!empty($item['price']))
                                        <span class="text-3xl lg:text-4xl font-black text-[#101010] tracking-tighter">
                                            {{ $item['price'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Список опций --}}
                        @if(!empty($item['features']))
                            <div class="mb-6 flex-grow text-gray-600 font-medium text-sm
                                        [&>ul]:list-none [&>ul]:p-0 [&>ul]:m-0 [&>ul]:divide-y [&>ul]:divide-gray-50
                                        [&>ul>li]:relative [&>ul>li]:pl-7 [&>ul>li]:py-2.5
                                        [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-3
                                        [&>ul>li]:before:w-4 [&>ul>li]:before:h-4 [&>ul>li]:before:bg-[url('data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23E85C24%22%20stroke-width%3D%223%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%2220%206%209%2017%204%2012%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E')] [&>ul>li]:before:bg-no-repeat [&>ul>li]:before:bg-center">
                                {!! \App\Support\RichHtml::sanitize($item['features']) !!}
                            </div>
                        @else
                            <div class="flex-grow"></div>
                        @endif

                        {{-- Кнопка → оплата тарифа со скидкой --}}
                        <div class="mt-auto">
                            <a href="{{ $href }}"
                               class="block w-full py-3 rounded-xl font-extrabold text-xs uppercase tracking-widest text-center transition-all duration-300
                                      {{ $isPopular
                                         ? 'bg-brand text-white hover:bg-brand-hover shadow-[0_8px_20px_rgba(232,92,36,0.3)] hover:-translate-y-0.5'
                                         : 'bg-gray-100 text-gray-900 hover:bg-gray-200 hover:-translate-y-0.5'
                                      }}">
                                {{ $item['button_text'] ?? 'Выбрать поток' }}
                            </a>
                        </div>

                    </div>
                </div>
            @endforeach
        </div>

    </div>

</section>
@endif
