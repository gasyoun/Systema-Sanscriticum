{{-- ==============================================================
     ТЕМЫ ВЕБИНАРОВ — список тем, которые разбираются в прямом эфире.
     Стиль единый с program_block/audience_block (тёплый фон, акцент
     #E85C24, скруглённые карточки). Обёрнут в <section> с .container,
     чтобы под него работал глобальный сдвиг под плавающую боковую форму.
     Кнопка CTA открывает ту же плавающую форму ($dispatch open-order-form).
============================================================== --}}
<section class="py-10 lg:py-16 bg-[#F9FAFB]">
    <div class="container mx-auto px-4">

        {{-- Заголовок секции --}}
        <div class="max-w-3xl mx-auto text-center mb-12 lg:mb-16 flex flex-col items-center">
            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] mb-4">
                {{ $data['title'] ?? 'Что разберем на вебинарах' }}
            </h2>
            <div class="w-24 h-1.5 bg-brand rounded-full"></div>

            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-base md:text-lg leading-relaxed mt-6">
                    {{ $data['subtitle'] }}
                </p>
            @endif
        </div>

        @if(!empty($data['topics']))
            <div class="max-w-5xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-5 lg:gap-6">
                @foreach($data['topics'] as $index => $topic)
                    <div class="group bg-white rounded-[2rem] p-6 md:p-7 border border-transparent shadow-sm
                                hover:shadow-2xl hover:shadow-orange-900/5 hover:border-orange-100
                                transition-all duration-500 flex items-start gap-5">

                        {{-- Номер темы --}}
                        <div class="flex-shrink-0 flex items-center justify-center w-11 h-11 md:w-12 md:h-12 rounded-2xl
                                    bg-gray-100 text-gray-400 text-lg font-black
                                    group-hover:bg-brand group-hover:text-white group-hover:rotate-6
                                    transition-all duration-300">
                            {{ $index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <h3 class="text-lg md:text-xl font-bold text-[#101010] leading-tight
                                       group-hover:text-brand transition-colors">
                                {{ $topic['title'] }}
                            </h3>

                            @if(!empty($topic['description']))
                                <div class="text-gray-500 text-sm md:text-base leading-relaxed mt-2
                                            [&_p]:mb-2 [&_p:last-child]:mb-0 [&_strong]:font-bold [&_strong]:text-gray-700
                                            [&_em]:italic [&_a]:text-brand [&_a]:underline [&_a]:font-semibold
                                            [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mt-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mt-2 [&_li]:mb-1">
                                    {!! \App\Support\SanitizedHtml::render($topic['description']) !!}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- CTA → плавающая форма заявки --}}
        @if(!empty($data['button_text']))
            <div class="text-center mt-12 lg:mt-14">
                <button type="button" @click.prevent="$dispatch('open-order-form')"
                        class="inline-flex items-center justify-center gap-2 bg-brand hover:bg-brand-hover text-white
                               font-extrabold py-4 px-10 rounded-xl text-base uppercase tracking-wider
                               shadow-lg shadow-orange-900/20 transform hover:-translate-y-0.5 transition-all duration-300">
                    {{ $data['button_text'] }}
                </button>
            </div>
        @endif

    </div>
</section>
