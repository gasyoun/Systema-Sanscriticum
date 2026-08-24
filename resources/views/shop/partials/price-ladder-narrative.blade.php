{{--
    Лесенка цен с позиционированием (H1293): единственное место, где форматы
    обучения сопоставлены по смыслу и цене — кому подходит формат, что меняется
    между ступенями, честные «от N ₽». Все числа — из ProductLadderAnchors
    (единственная точка правды); отсутствующая цена просто не показывается,
    хардкод цен запрещен (fence rule 2).

    Ожидает: $ladder (ProductLadderAnchors::resolve())
--}}

@push('head')
    @php
        // SEO: OfferCatalog из AggregateOffer «от N ₽» — паттерн Offer из
        // shop/show.blade.php, адаптированный под многоформатную страницу
        // (страница не про один курс, поэтому Course-нода здесь неуместна).
        $ladderOffers = array_values(array_filter([
            $ladder['minRecordedPrice'] ? [
                '@type' => 'AggregateOffer',
                'name' => 'Курс санскрита в записи',
                'lowPrice' => number_format((float) $ladder['minRecordedPrice'], 2, '.', ''),
                'priceCurrency' => 'RUB',
                'availability' => 'https://schema.org/InStock',
                'url' => $ladder['catalogUrl'].'?format=recorded',
            ] : null,
            $ladder['minBlockPrice'] ? [
                '@type' => 'AggregateOffer',
                'name' => 'Живой курс санскрита — оплата по блокам',
                'lowPrice' => number_format((float) $ladder['minBlockPrice'], 2, '.', ''),
                'priceCurrency' => 'RUB',
                'availability' => 'https://schema.org/InStock',
                'url' => $ladder['catalogUrl'].'?format=live',
            ] : null,
            $ladder['minLiveFullPrice'] ? [
                '@type' => 'AggregateOffer',
                'name' => 'Живой курс санскрита целиком',
                'lowPrice' => number_format((float) $ladder['minLiveFullPrice'], 2, '.', ''),
                'priceCurrency' => 'RUB',
                'availability' => 'https://schema.org/InStock',
                'url' => $ladder['catalogUrl'].'?format=live',
            ] : null,
        ]));
    @endphp
    @if (!empty($ladderOffers))
    <script type="application/ld+json">
{{-- JSON_HEX_TAG: названия курсов в <script>-контексте не могут закрыть тег --}}
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'OfferCatalog',
    'name' => 'Форматы обучения санскриту',
    'url' => $ladder['catalogUrl'].'#ceny',
    'itemListElement' => $ladderOffers,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
    </script>
    @endif
@endpush

<section id="ceny" class="mt-16 lg:mt-20 scroll-mt-24" data-analytics="price-ladder-narrative">
    <h2 class="text-3xl font-bold text-white mb-3">Сколько стоит обучение</h2>
    <p class="text-slate-400 mb-10 max-w-3xl leading-relaxed">
        Цены открыты: вы видите их до регистрации и разговора с куратором.
        Форматов три, различаются они темпом и участием преподавателя.
        Точная цена каждого курса — на его странице; здесь — как выбрать между форматами.
    </p>

    <div class="space-y-5">
        {{-- Ступень 1: бесплатный пример урока --}}
        <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6 lg:p-8 md:flex md:items-start md:gap-8">
            <div class="md:w-64 shrink-0 mb-4 md:mb-0">
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-400">Бесплатно</span>
                <h3 class="text-xl font-bold text-white mt-1">Пример урока</h3>
            </div>
            <div class="flex-1">
                <p class="text-sm text-slate-400 leading-relaxed mb-4">
                    Для тех, кто присматривается. Настоящий урок — без регистрации и оплаты:
                    видно, как устроены занятия и подходит ли вам подача. Это честнее любого
                    описания, поэтому сравнение форматов стоит начать отсюда.
                </p>
                <a href="{{ $ladder['freeUrl'] }}"
                   class="inline-flex items-center px-5 py-2.5 bg-emerald-500/15 border border-emerald-500/40 hover:bg-emerald-500 hover:text-white text-emerald-400 text-xs font-bold rounded-xl transition-all">
                    Смотреть пример урока
                </a>
            </div>
        </div>

        {{-- Ступень 2: курс в записи --}}
        <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6 lg:p-8 md:flex md:items-start md:gap-8">
            <div class="md:w-64 shrink-0 mb-4 md:mb-0">
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-400">В записи</span>
                <h3 class="text-xl font-bold text-white mt-1">Курс в своем темпе</h3>
                @if ($ladder['minRecordedPrice'])
                    <div class="text-sm font-bold text-white mt-2">от {{ number_format($ladder['minRecordedPrice'], 0, '.', ' ') }} ₽ за курс</div>
                @endif
            </div>
            <div class="flex-1">
                <p class="text-sm text-slate-400 leading-relaxed mb-4">
                    Для тех, кому важен свой темп. Расписания и дедлайнов нет: вы начинаете
                    в любой день, видео остаются с вами. По сравнению с примером урока это
                    полный курс — со структурой, материалами и порядком тем.
                </p>
                <a href="{{ $ladder['catalogUrl'] }}?format=recorded"
                   class="inline-flex items-center px-5 py-2.5 bg-indigo-500/15 border border-indigo-500/40 hover:bg-indigo-500 hover:text-white text-indigo-400 text-xs font-bold rounded-xl transition-all">
                    Курсы в записи
                </a>
            </div>
        </div>

        {{-- Ступень 3: живой курс — блоками или целиком --}}
        <div class="rounded-2xl bg-[#111622] border border-brand/40 p-6 lg:p-8 md:flex md:items-start md:gap-8 relative overflow-hidden">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-brand/15 rounded-full blur-3xl pointer-events-none"></div>
            <div class="md:w-64 shrink-0 mb-4 md:mb-0 relative">
                <span class="text-[10px] font-black uppercase tracking-widest text-brand">Живой курс</span>
                <h3 class="text-xl font-bold text-white mt-1">С преподавателем</h3>
                @if ($ladder['minBlockPrice'])
                    <div class="text-sm font-bold text-white mt-2">от {{ number_format($ladder['minBlockPrice'], 0, '.', ' ') }} ₽ за блок</div>
                @endif
            </div>
            <div class="flex-1 relative">
                <p class="text-sm text-slate-400 leading-relaxed mb-3">
                    Для тех, кому нужен преподаватель. Живые занятия в группе, разбор
                    вопросов, учебный чат — то, чего запись дать не может. Платить за весь
                    курс сразу не нужно: курс разбит на блоки, вы оплачиваете ближайший
                    и решаете о продолжении после него.
                </p>
                @if ($ladder['minLiveFullPrice'])
                    <p class="text-sm text-slate-400 leading-relaxed mb-4">
                        Если удобнее одна оплата вместо нескольких, живой курс можно взять
                        целиком — от {{ number_format($ladder['minLiveFullPrice'], 0, '.', ' ') }} ₽:
                        все блоки закреплены сразу, следить за оплатой каждого не нужно.
                    </p>
                @endif
                <a href="{{ $ladder['catalogUrl'] }}?format=live"
                   class="inline-flex items-center px-5 py-2.5 bg-brand hover:bg-brand/85 text-white text-xs font-bold rounded-xl transition-all shadow-[0_0_15px_rgba(232,92,36,0.3)]">
                    Живые курсы
                </a>
            </div>
        </div>
    </div>

    <p class="text-slate-400 text-sm leading-relaxed mt-8 max-w-3xl">
        Если сомневаетесь — начните с бесплатного урока, а страница
        <a href="{{ route('shop.start') }}" class="text-[#38BDF8] hover:underline font-bold">«С чего начать»</a>
        подберет курс под ваш уровень.
    </p>
</section>
