@php
    $data = $block['data'] ?? $data ?? [];

    // Контент
    $title = $data['title'] ?? 'Курс завершён';
    $subtitle = $data['subtitle'] ?? 'Повторный набор не планируется. Все лекции доступны в записи — учитесь в своём темпе с пожизненным доступом.';
    $buttonText = $data['button_text'] ?? 'Купить запись курса';
    $badges = $data['badges'] ?? [];

    // Дизайн
    $bgColor = $data['bg_color'] ?? '#1E4633';
    $textColor = $data['text_color'] ?? '#ffffff';
    $buttonBgColor = $data['button_bg_color'] ?? '#E85C24';
    $buttonTextColor = $data['button_text_color'] ?? '#ffffff';

    // Ссылка на карточку курса в витрине (/online/kursy/{slug}).
    // Кнопку показываем только если курс существует и виден в магазине.
    $course = !empty($data['course_id'])
        ? \App\Models\Course::where('is_visible', true)->find($data['course_id'])
        : null;
    $buttonUrl = $course ? route('shop.course.show', $course->slug) : null;
@endphp

<section class="py-4 lg:py-6 relative z-10 font-nunito">
    <div class="container mx-auto px-4">
        <div class="relative w-full rounded-2xl lg:rounded-[2rem] overflow-hidden shadow-[0_20px_40px_rgba(0,0,0,0.1)] py-8 px-6 md:py-10 md:px-12 text-center flex flex-col items-center gap-5"
             style="background-color: {{ $bgColor }};">

            <div class="absolute inset-0 z-0 bg-gradient-to-b from-white/5 to-transparent pointer-events-none"></div>

            <div class="relative z-10 flex flex-col items-center gap-4 max-w-2xl">
                <h2 class="text-2xl md:text-3xl lg:text-[34px] font-extrabold leading-tight"
                    style="color: {{ $textColor }};">
                    {{ $title }}
                </h2>

                @if($subtitle)
                    <p class="text-sm md:text-base font-medium opacity-90 leading-relaxed"
                       style="color: {{ $textColor }};">
                        {{ $subtitle }}
                    </p>
                @endif

                @if(!empty($badges))
                    <div class="flex flex-wrap justify-center gap-2 mt-1">
                        @foreach($badges as $badge)
                            @if(!empty($badge['text']))
                                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wide bg-white/10 border border-white/20"
                                      style="color: {{ $textColor }};">
                                    {{ $badge['text'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if($buttonUrl)
                    <a href="{{ $buttonUrl }}"
                       class="mt-3 inline-flex items-center justify-center px-10 py-4 rounded-[14px] font-extrabold text-sm uppercase tracking-widest transition-all duration-300 hover:-translate-y-1 active:translate-y-0 shadow-lg hover:shadow-xl"
                       style="background-color: {{ $buttonBgColor }}; color: {{ $buttonTextColor }};">
                        {{ $buttonText }}
                        <svg class="w-4 h-4 ml-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                @endif
            </div>

        </div>
    </div>
</section>
