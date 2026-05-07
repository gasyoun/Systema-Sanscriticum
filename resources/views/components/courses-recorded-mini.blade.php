@props([
    'courses' => null,
    'variant' => 'dark',
    'title' => 'Курсы в записи',
    'subtitle' => 'Учитесь в своём темпе — записи лекций с пожизненным доступом.',
    'width' => 'normal', // normal | wide
])

@php
    $courses = $courses ?? ($recordedCoursesMini ?? collect());
@endphp

@if($courses && $courses->count() > 0)
    @php
        $isDark = $variant === 'dark';
        $sectionBg   = $isDark ? '' : 'bg-[#FAF8F5]';
        $titleColor  = $isDark ? 'text-white' : 'text-[#1F1B16]';
        $subColor    = $isDark ? 'text-gray-300' : 'text-[#6B6258]';
        $accent      = $isDark ? 'text-[#E85C24]' : 'text-[#E85C24]';
        $cardBg      = $isDark ? 'bg-[#161b28] border-gray-700/60 hover:border-[#E85C24]/60' : 'bg-white border-[#E8E0D2] hover:border-[#E85C24]/40';
        $cardText    = $isDark ? 'text-white' : 'text-[#1F1B16]';
        $imgBg       = $isDark ? 'bg-gray-800' : 'bg-[#F2EBE1]';
        $priceMuted  = $isDark ? 'text-gray-400' : 'text-[#6B6258]';
        $linkColor   = $isDark ? 'text-[#E85C24]' : 'text-[#E85C24]';
        $hoverShadow = $isDark ? 'group-hover:shadow-[0_0_25px_rgba(232,92,36,0.15)]' : 'group-hover:shadow-lg';
        $allUrl      = route('shop.index') . '?format=recorded';
        $perPage     = 6;
        $chunks      = $courses->chunk($perPage)->values();
        $pageCount   = $chunks->count();
        $hasSlider   = $pageCount > 1;
        $arrowBase   = $isDark
            ? 'bg-[#161b28] border-gray-700/60 text-white hover:border-[#E85C24]/60 hover:text-[#E85C24]'
            : 'bg-white border-[#E8E0D2] text-[#1F1B16] hover:border-[#E85C24]/60 hover:text-[#E85C24]';
        $dotIdle     = $isDark ? 'bg-gray-600/70' : 'bg-[#E8E0D2]';
        $dotActive   = 'bg-[#E85C24]';
        $maxWidth    = $width === 'wide' ? 'max-w-[88rem]' : 'max-w-7xl';
    @endphp

    <section class="{{ $sectionBg }} py-12 md:py-16">
        <div class="container mx-auto px-4 {{ $maxWidth }}"
             @if($hasSlider) x-data="{ slide: 0, pages: {{ $pageCount }}, prev() { this.slide = (this.slide - 1 + this.pages) % this.pages }, next() { this.slide = (this.slide + 1) % this.pages } }" @endif>

            <div class="flex items-end justify-between mb-8 flex-wrap gap-4">
                <div>
                    <h2 class="text-2xl md:text-3xl font-extrabold {{ $titleColor }} tracking-tight">
                        {{ $title }}
                    </h2>
                    <div class="w-12 h-1 bg-[#E85C24] mt-3 mb-3 rounded-full"></div>
                    <p class="{{ $subColor }} text-sm md:text-base max-w-xl">
                        {{ $subtitle }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    @if($hasSlider)
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="prev()"
                                    aria-label="Предыдущие курсы"
                                    class="w-9 h-9 rounded-full border flex items-center justify-center transition-colors {{ $arrowBase }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <button type="button"
                                    @click="next()"
                                    aria-label="Следующие курсы"
                                    class="w-9 h-9 rounded-full border flex items-center justify-center transition-colors {{ $arrowBase }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>
                    @endif
                    <a href="{{ $allUrl }}"
                       class="{{ $linkColor }} text-sm font-bold hover:underline inline-flex items-center group">
                        Все курсы
                        <svg class="w-4 h-4 ml-1 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                        </svg>
                    </a>
                </div>
            </div>

            <div @if($hasSlider) class="overflow-hidden" @endif>
                <div @if($hasSlider) class="flex transition-transform duration-500 ease-out" x-bind:style="`transform: translateX(-${slide * 100}%)`" @endif>
                    @foreach($chunks as $chunk)
                        <div @if($hasSlider) class="w-full shrink-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6" @else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6" @endif>
                            @foreach($chunk as $course)
                                @php
                                    $minPrice = $course->tariffs
                                        ->where('is_active', true)
                                        ->min('price');
                                    $category = $course->categories->first();
                                    $categoryColor = $category->color ?? '#E85C24';
                                @endphp
                                <a href="{{ route('shop.course.show', $course->slug) }}"
                                   class="group flex flex-col {{ $cardBg }} border rounded-xl p-4 md:p-5 transition-all duration-300 hover:-translate-y-0.5 {{ $hoverShadow }}">

                                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                                        <span class="bg-indigo-500/90 text-white text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded">
                                            В записи
                                        </span>
                                        @if($category)
                                            <span class="text-[11px] font-bold uppercase tracking-wider"
                                                  style="color: {{ $categoryColor }}">
                                                {{ $category->name }}
                                            </span>
                                        @endif
                                    </div>

                                    <h3 class="font-bold {{ $cardText }} text-base leading-tight mb-3 line-clamp-2 group-hover:text-[#E85C24] transition-colors">
                                        {{ $course->title }}
                                    </h3>

                                    <div class="mt-auto pt-3 border-t {{ $isDark ? 'border-gray-700/50' : 'border-[#E8E0D2]' }} flex items-center justify-between gap-2">
                                        @if($minPrice)
                                            <span class="text-xs {{ $priceMuted }}">
                                                от
                                                <span class="text-sm font-extrabold {{ $cardText }}">
                                                    {{ number_format((float) $minPrice, 0, '.', ' ') }} ₽
                                                </span>
                                            </span>
                                        @else
                                            <span></span>
                                        @endif
                                        <span class="text-xs font-bold {{ $linkColor }} inline-flex items-center">
                                            Подробнее
                                            <svg class="w-3.5 h-3.5 ml-1 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                            </svg>
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            @if($hasSlider)
                <div class="flex items-center justify-center gap-2 mt-6">
                    @for($i = 0; $i < $pageCount; $i++)
                        <button type="button"
                                @click="slide = {{ $i }}"
                                aria-label="Страница {{ $i + 1 }}"
                                class="h-2 rounded-full transition-all duration-300"
                                x-bind:class="slide === {{ $i }} ? 'w-6 {{ $dotActive }}' : 'w-2 {{ $dotIdle }}'"></button>
                    @endfor
                </div>
            @endif

        </div>
    </section>
@endif
