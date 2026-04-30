@props(['course', 'purchasedByCourse' => []])

@php
    $courseKeys = $purchasedByCourse[$course->id] ?? [];
    $hasAnyPurchased = !empty($courseKeys);
    $fullPurchased = in_array('full', $courseKeys, true);
    $fullTariff = $course->tariffs->where('type', '!=', 'block')->first();
    $blockTariff = $course->tariffs->where('type', 'block')->sortBy('price')->first();
@endphp

<div class="relative flex flex-col bg-[#111622] rounded-2xl border border-[#1F2636] hover:border-[#E85C24]/50 hover:shadow-[0_0_30px_rgba(232,92,36,0.05)] transition-all duration-300 group">

    {{-- ============================================ --}}
    {{-- ОБЛОЖКА                                        --}}
    {{-- ============================================ --}}
    <a href="{{ route('shop.course.show', $course->slug) }}"
       class="relative w-full aspect-[4/3] bg-gradient-to-br from-slate-800 to-[#0A0D14] flex items-center justify-center border-b border-[#1F2636] overflow-hidden group/img block rounded-t-2xl">

        @if($course->image_path)
            <img src="{{ Storage::url($course->image_path) }}"
                 alt="{{ $course->title }}"
                 class="absolute inset-0 w-full h-full object-cover group-hover/img:scale-105 transition-transform duration-700 opacity-80">
            <div class="absolute inset-0 bg-gradient-to-t from-[#111622] via-transparent to-transparent opacity-80"></div>
        @else
            <i class="fas fa-om text-6xl text-slate-700/30 group-hover/img:scale-110 transition-transform duration-500"></i>
        @endif

        {{-- Бейдж формата (live / recorded) --}}
        <div class="absolute top-3 right-3 z-20">
            @if($course->isLive())
                <span class="inline-flex items-center gap-1.5 bg-rose-500 text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md shadow-[0_4px_12px_rgba(244,63,94,0.5)] tracking-wider">
                    <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                    Идёт сейчас
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 bg-indigo-500/90 text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md tracking-wider">
                    <i class="fas fa-play-circle text-[9px]"></i>
                    В записи
                </span>
            @endif
        </div>

        {{-- Мета-бейджи: лекции, часы --}}
        @if($course->lessons_count || $course->hours_count)
            <div class="absolute bottom-3 left-3 z-20 flex items-center gap-1.5">
                @if($course->lessons_count)
                    <span class="inline-flex items-center gap-1.5 bg-[#E85C24] text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md shadow-[0_4px_12px_rgba(232,92,36,0.5)] tracking-wider">
                        <i class="fas fa-play-circle text-[9px]"></i>
                        {{ $course->lessons_count }} {{ trans_choice('лекция|лекции|лекций', $course->lessons_count) }}
                    </span>
                @endif
                @if($course->hours_count)
                    <span class="inline-flex items-center gap-1.5 bg-black/60 backdrop-blur-sm text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md tracking-wider border border-white/10">
                        <i class="far fa-clock text-[9px]"></i>
                        {{ $course->hours_count }} ч
                    </span>
                @endif
            </div>
        @endif
    </a>

    {{-- ============================================ --}}
    {{-- ТЕЛО КАРТОЧКИ                                  --}}
    {{-- ============================================ --}}
    <div class="p-6 flex flex-col flex-grow justify-between bg-[#111622] z-10 relative rounded-b-2xl">

        <div>
            {{-- Категории --}}
            @if($course->categories->isNotEmpty())
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach($course->categories as $cat)
                        <span class="text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded"
                              @style(['background-color: ' . $cat->color . '20; color: ' . $cat->color => $cat->color])
                              @class(['bg-[#1F2636] text-slate-300' => !$cat->color])>
                            {{ $cat->name }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="text-[#38BDF8] text-[10px] font-black uppercase tracking-widest mb-2 flex justify-between items-center">
                <span>{{ $course->teacher?->name ?? 'Онлайн-программа' }}</span>
                @if($course->hours_count)
                    <span class="text-slate-500"><i class="far fa-clock mr-1"></i>{{ $course->hours_count }}ч</span>
                @endif
            </div>

            <a href="{{ route('shop.course.show', $course->slug) }}" class="block">
                <h2 class="text-xl font-bold text-white mb-3 leading-tight group-hover:text-[#E85C24] transition-colors">
                    {{ $course->title }}
                </h2>
            </a>

            @if($course->description)
                <p class="text-sm text-slate-400 line-clamp-3 leading-relaxed mb-4">
                    {{ Str::limit(strip_tags($course->description), 100) }}
                </p>
            @endif
        </div>

        {{-- ============================================ --}}
        {{-- БЛОК ЦЕН И ТАРИФОВ                            --}}
        {{-- ============================================ --}}
        <div class="mt-auto pt-5 border-t border-[#1F2636]/60">

            @if($course->tariffs->count() > 0)

                <div class="space-y-2 mb-5">
                    {{-- Тариф: весь курс --}}
                    @if($fullTariff)
                        @php
                            $fullFinalPrice = auth()->check() ? $fullTariff->calculateFinalPriceForUser(auth()->user()) : $fullTariff->price;
                            $fullDiscountPercent = auth()->check() ? $fullTariff->getDiscountPercentForUser(auth()->user()) : 0;
                        @endphp

                        <div class="flex justify-between items-center">
                            <span class="text-slate-400 text-xs font-medium">Весь курс</span>
                            <div class="text-right flex items-center justify-end flex-wrap gap-x-1.5">
                                @if($fullFinalPrice < $fullTariff->price)
                                    <span class="text-slate-500 line-through text-[10px] decoration-slate-600/50">{{ number_format($fullTariff->price, 0, '.', ' ') }}</span>
                                    <span class="font-bold text-[#38BDF8] text-sm">{{ number_format($fullFinalPrice, 0, '.', ' ') }} ₽</span>

                                    @if($fullDiscountPercent > 0)
                                        <span class="text-[9px] text-emerald-400 font-bold uppercase tracking-wide">-{{ $fullDiscountPercent }}%</span>
                                    @elseif($fullFinalPrice == 0)
                                        <span class="text-[9px] text-green-400 font-bold uppercase tracking-wide">Куплено</span>
                                    @else
                                        <span class="text-[9px] text-indigo-400 font-bold uppercase tracking-wide">Апгрейд</span>
                                    @endif
                                @else
                                    <span class="font-bold text-white text-sm">{{ number_format($fullTariff->price, 0, '.', ' ') }} ₽</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Тариф: по модулям --}}
                    @if($blockTariff)
                        @php
                            $blockFinalPrice = auth()->check() ? $blockTariff->calculateFinalPriceForUser(auth()->user()) : $blockTariff->price;
                            $blockDiscountPercent = auth()->check() ? $blockTariff->getDiscountPercentForUser(auth()->user()) : 0;
                        @endphp

                        <div class="flex justify-between items-center">
                            <span class="text-slate-400 text-xs font-medium">По модулям</span>
                            <div class="text-right flex items-center justify-end flex-wrap gap-x-1.5">
                                @if($blockFinalPrice < $blockTariff->price)
                                    <span class="text-slate-500 line-through text-[10px] decoration-slate-600/50">{{ number_format($blockTariff->price, 0, '.', ' ') }}</span>
                                    <span class="font-bold text-[#38BDF8] text-sm">{{ number_format($blockFinalPrice, 0, '.', ' ') }} ₽</span>

                                    @if($blockDiscountPercent > 0)
                                        <span class="text-[9px] text-emerald-400 font-bold uppercase tracking-wide">-{{ $blockDiscountPercent }}%</span>
                                    @endif
                                    <span class="text-[10px] text-slate-500 font-normal">/ блок</span>
                                @else
                                    <span class="font-bold text-[#38BDF8] text-sm">
                                        {{ number_format($blockTariff->price, 0, '.', ' ') }} ₽
                                        <span class="text-[10px] text-slate-500 font-normal">/ блок</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Бейдж "куплено" --}}
                @if($hasAnyPurchased)
                    <div class="inline-flex items-center gap-1.5 bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded mb-3">
                        <i class="fas fa-check-circle"></i>
                        {{ $fullPurchased ? 'Весь курс куплен' : 'Блоки куплены' }}
                    </div>
                @endif

                <a href="{{ route('shop.course.show', $course->slug) }}#tariffs"
                   class="flex justify-center items-center w-full py-3 px-4 bg-[#1F2636] hover:bg-[#E85C24] text-white text-xs font-bold rounded-xl transition-all duration-300 group/btn shadow-md hover:shadow-[0_0_15px_rgba(232,92,36,0.4)] hover:-translate-y-0.5">
                    Выбрать тариф
                    <i class="fas fa-arrow-right ml-2 opacity-0 -translate-x-2 group-hover/btn:opacity-100 group-hover/btn:translate-x-0 transition-all duration-300"></i>
                </a>

            @else
                <div class="text-center bg-[#1F2636]/30 rounded-xl py-4 mt-2 border border-[#1F2636]/50">
                    <i class="fas fa-lock text-slate-500 mb-1"></i>
                    <div class="text-slate-400 text-xs font-medium">Набор закрыт</div>
                </div>
            @endif

        </div>

    </div>
</div>