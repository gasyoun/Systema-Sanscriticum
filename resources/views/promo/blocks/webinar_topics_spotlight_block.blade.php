{{-- ==============================================================
     ТЕМЫ ВЕБИНАРОВ — светлый «editorial»-вариант. Темы строками во всю
     ширину: залитый номер-бейдж (как в program_block), заголовок темы,
     описание и стрелка → цепляет и тянет читать. Контент-поля те же,
     что у светлого блока 5.1 (взаимозаменяемы).
     Обёрнут в <section> с .container → работает глобальный сдвиг под
     плавающую боковую форму. CTA открывает её ($dispatch open-order-form).
============================================================== --}}
<section class="py-12 lg:py-20 bg-[#F9FAFB]">
    <div class="container mx-auto px-4">

        {{-- Шапка --}}
        <div class="max-w-3xl mb-10 lg:mb-14">
            @if(!empty($data['badge']))
                <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-widest
                             bg-brand/10 text-brand border border-brand/20">
                    <span class="w-2 h-2 rounded-full bg-brand animate-pulse"></span>
                    {{ $data['badge'] }}
                </span>
            @endif

            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] leading-tight mt-5">
                {{ $data['title'] ?? 'О чём будем говорить' }}
            </h2>

            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-base md:text-lg leading-relaxed mt-4">
                    {{ $data['subtitle'] }}
                </p>
            @endif
        </div>

        {{-- Список тем --}}
        @if(!empty($data['topics']))
            <div class="max-w-5xl border-t border-gray-200">
                @foreach($data['topics'] as $index => $topic)
                    <div class="group relative flex items-start gap-5 md:gap-8 py-6 md:py-8 px-3 md:px-5
                                border-b border-gray-200 transition-all duration-300 cursor-default
                                hover:bg-white rounded-2xl">

                        {{-- Залитый номер-бейдж --}}
                        <div class="flex-shrink-0 flex items-center justify-center w-11 h-11 md:w-12 md:h-12 rounded-2xl
                                    bg-brand text-white text-lg font-black shadow-sm
                                    group-hover:rotate-6 transition-transform duration-300">
                            {{ sprintf('%02d', $index + 1) }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <h3 class="text-lg md:text-2xl font-bold text-[#101010] leading-tight transition-colors duration-300
                                       group-hover:text-brand">
                                {{ $topic['title'] }}
                            </h3>

                            @if(!empty($topic['description']))
                                <div class="text-gray-500 text-sm md:text-base leading-relaxed mt-2 max-w-2xl
                                            [&_p]:mb-2 [&_p:last-child]:mb-0 [&_strong]:font-bold [&_strong]:text-gray-700
                                            [&_em]:italic [&_a]:text-brand [&_a]:underline [&_a]:font-semibold
                                            [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mt-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mt-2 [&_li]:mb-1">
                                    {!! \App\Support\RichHtml::sanitize($topic['description']) !!}
                                </div>
                            @endif
                        </div>

                        {{-- Стрелка --}}
                        <div class="shrink-0 self-center w-10 h-10 md:w-11 md:h-11 rounded-full flex items-center justify-center
                                    border border-gray-200 text-gray-300 transition-all duration-300
                                    group-hover:bg-brand group-hover:border-brand group-hover:text-white
                                    group-hover:translate-x-1">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- CTA → плавающая форма заявки --}}
        @if(!empty($data['button_text']))
            <div class="mt-10 lg:mt-14 max-w-5xl">
                <button type="button" @click.prevent="$dispatch('open-order-form')"
                        class="group inline-flex items-center justify-center gap-3 bg-brand hover:bg-brand-hover text-white
                               font-extrabold py-4 px-10 rounded-xl text-base uppercase tracking-wider
                               shadow-lg shadow-orange-900/20 transform hover:-translate-y-0.5 transition-all duration-300">
                    {{ $data['button_text'] }}
                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </button>
            </div>
        @endif

    </div>
</section>
