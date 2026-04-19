@php
    // Нормализуем входные данные — блок защищён от пустого/битого JSON
    $title    = $data['title'] ?? 'Частые вопросы';
    $subtitle = $data['subtitle'] ?? null;
    $items    = collect($data['items'] ?? [])
        ->filter(fn ($i) => !empty($i['question']) && !empty($i['answer']))
        ->values();
@endphp

@if($items->isNotEmpty())
<section class="py-10 lg:py-16 bg-[#F9FAFB]" x-data="{ activeFaq: 0 }">
    <div class="container mx-auto px-4">

        {{-- Заголовок секции (стиль program_block) --}}
        <div class="max-w-3xl mx-auto text-center mb-12 md:mb-16 flex flex-col items-center">
            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] mb-4">
                {{ $title }}
            </h2>
            <div class="w-24 h-1.5 bg-[#E85C24] rounded-full"></div>

            @if($subtitle)
                <p class="mt-6 text-base md:text-lg text-gray-500 leading-relaxed max-w-2xl">
                    {{ $subtitle }}
                </p>
            @endif
        </div>

        {{-- Аккордеон --}}
        <div class="max-w-4xl mx-auto space-y-4">
            @foreach($items as $index => $item)
                <div class="bg-white rounded-[2rem] overflow-hidden transition-all duration-500 border border-transparent"
                     :class="activeFaq === {{ $index }} ? 'shadow-2xl shadow-orange-900/5 border-orange-100' : 'shadow-sm hover:shadow-md'">

                    {{-- Кнопка-заголовок --}}
                    <button type="button"
                            @click="activeFaq === {{ $index }} ? activeFaq = null : activeFaq = {{ $index }}"
                            class="w-full flex items-center justify-between p-6 md:p-8 text-left focus:outline-none group transition-colors"
                            :class="activeFaq === {{ $index }} ? 'bg-orange-50/30' : ''"
                            :aria-expanded="activeFaq === {{ $index }} ? 'true' : 'false'"
                            aria-controls="faq-answer-{{ $index }}">

                        <div class="flex items-center gap-5 md:gap-7">
                            {{-- Иконка вопроса (вместо номера модуля) --}}
                            <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 md:w-12 md:h-12 rounded-2xl transition-all duration-300"
                                 :class="activeFaq === {{ $index }}
                                     ? 'bg-[#E85C24] text-white rotate-6'
                                     : 'bg-gray-100 text-gray-400 group-hover:bg-orange-100 group-hover:text-[#E85C24]'">
                                <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                          d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093M12 17h.01"/>
                                </svg>
                            </div>

                            <span class="text-base md:text-xl font-bold text-[#101010] group-hover:text-[#E85C24] transition-colors leading-tight">
                                {{ $item['question'] }}
                            </span>
                        </div>

                        {{-- Стрелка (идентична program_block) --}}
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center transition-all duration-500 shrink-0 ml-4"
                             :class="activeFaq === {{ $index }} ? 'bg-[#E85C24] text-white rotate-180' : 'bg-gray-100 text-gray-400'">
                            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    {{-- Ответ --}}
                    <div x-show="activeFaq === {{ $index }}"
                         x-collapse
                         x-cloak
                         id="faq-answer-{{ $index }}">
                        <div class="px-6 md:px-8 pb-8 pt-2">
                            <div class="text-gray-600 text-base md:text-lg leading-relaxed
                                        [&>p]:mb-4 [&>p:last-child]:mb-0
                                        [&>a]:text-[#E85C24] [&>a]:font-semibold [&>a]:underline [&>a]:underline-offset-2 hover:[&>a]:text-[#d04a15]
                                        [&>ul]:list-none [&>ul]:p-0 [&>ul]:m-0 [&>ul]:mt-3
                                        [&>ul>li]:relative [&>ul>li]:pl-10 [&>ul>li]:mb-3
                                        [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-1
                                        [&>ul>li]:before:flex [&>ul>li]:before:items-center [&>ul>li]:before:justify-center
                                        [&>ul>li]:before:w-6 [&>ul>li]:before:h-6 [&>ul>li]:before:bg-orange-100
                                        [&>ul>li]:before:text-[#E85C24] [&>ul>li]:before:content-['✓']
                                        [&>ul>li]:before:rounded-lg [&>ul>li]:before:font-black [&>ul>li]:before:text-sm
                                        [&>ol]:list-decimal [&>ol]:pl-6 [&>ol]:mt-3
                                        [&>ol>li]:mb-2 [&>ol>li]:pl-2">
                                {!! $item['answer'] !!}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============================================ --}}
    {{-- SEO: JSON-LD FAQPage schema для Google      --}}
    {{-- ============================================ --}}
    @php
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => $items->map(fn ($item) => [
                '@type'          => 'Question',
                'name'           => strip_tags($item['question']),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => trim(strip_tags($item['answer'])),
                ],
            ])->values()->all(),
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
</section>

<style>
    [x-cloak] { display: none !important; }
</style>
@endif