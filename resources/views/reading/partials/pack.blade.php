{{--
    H2110 — the reading-pack body, extracted verbatim from reading/kosha-demo.blade.php so
    the public demo page and the in-cabinet cohort route render the SAME markup from the
    SAME feed shape instead of drifting into two readers.

    Contract (reading_pack_v1): $pack with slug/title/ref/text_name/source/stats/sentences[],
    $difficulty (?array, advisory) and $rankedPacks (list). Optional per-token `gloss_ru`
    ({surface, lemma}) is rendered when the feed carries it — hitopadesa-0 does, nala-1 does
    not, so the public page's output is unchanged by its introduction.
--}}
<header class="text-center mb-10">
    <h1 class="text-3xl md:text-4xl font-extrabold text-[#E85C24] tracking-tight">{{ $pack['title'] }}</h1>
    <p class="mt-2 text-gray-400 text-sm">{{ $pack['ref'] }} · {{ $pack['text_name'] }} · {{ $pack['source'] }}</p>
    <p class="mt-3 text-gray-500 text-xs">
        <b class="text-gray-300">{{ $pack['stats']['sentences'] }}</b> предложений ·
        <b class="text-gray-300">{{ $pack['stats']['tokens'] }}</b> слов ·
        <b class="text-gray-300">{{ $pack['stats']['linked_tokens'] }}</b> разобрано
        (<b class="text-gray-300">{{ $pack['stats']['link_rate_pct'] }}%</b>)
    </p>
    <p class="mt-4 text-gray-500 text-xs max-w-xl mx-auto">
        Нажмите на слово, чтобы увидеть лемму, форму и значение.
    </p>
</header>

{{-- ═════ Сложность (H965, Hop C — справочные данные, не влияют на порядок курса) ═════ --}}
@if($difficulty)
    <div class="max-w-3xl mx-auto mb-8">
        <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
            <div class="flex items-baseline justify-between gap-4 mb-3">
                <h2 class="text-sm font-black uppercase tracking-widest text-gray-500">Сложность текста</h2>
                <span class="text-xs text-gray-500">№{{ $difficulty['order'] }} из {{ count($rankedPacks) }} оцененных — от простого к сложному</span>
            </div>
            <div class="text-2xl font-extrabold text-[#E85C24]">{{ number_format($difficulty['difficulty'], 3) }}</div>
            <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs text-gray-400">
                <div>лексика <b class="text-gray-200">{{ number_format($difficulty['vocab'], 2) }}</b></div>
                <div>сандхи <b class="text-gray-200">{{ number_format($difficulty['sandhi'], 2) }}</b></div>
                <div>морфология <b class="text-gray-200">{{ number_format($difficulty['morphology'], 2) }}</b></div>
                <div>сложные слова <b class="text-gray-200">{{ number_format($difficulty['compound'], 2) }}</b></div>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                Оценка kosha (H949): взвешенная сумма по четырем корпусным осям — справочно, порядок курса не меняет.
            </p>
        </section>
    </div>
@endif

<div class="space-y-6 max-w-3xl mx-auto">
    @foreach($pack['sentences'] as $sentence)
        <article class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
            <div class="text-xs uppercase tracking-widest text-gray-500 mb-2">{{ $sentence['locus'] }}</div>
            <div class="deva text-xl text-white mb-3 leading-relaxed">
                @foreach($sentence['tokens'] as $token)
                    <details class="inline-block align-baseline mr-1 mb-1 group">
                        <summary class="inline-block cursor-pointer px-1.5 py-0.5 rounded-md bg-gray-800/70 text-gray-100 hover:bg-gray-700 hover:text-[#E85C24] transition-colors marker:content-none [&::-webkit-details-marker]:hidden">{{ $token['form'] }}</summary>
                        <div class="mt-1 mb-2 text-xs font-sans normal-case tracking-normal text-gray-400 bg-[#0f1420] rounded-lg px-3 py-2 inline-block">
                            <span class="text-gray-300 font-semibold" lang="sa-Latn">{{ $token['lemma'] }}</span>
                            @if(!empty($token['morph']))
                                <span class="text-gray-500"> · {{ $token['morph'] }}</span>
                            @endif
                            @php($glossRu = $token['gloss_ru']['surface'] ?? ($token['gloss_ru']['lemma'] ?? null))
                            @if(!empty($glossRu))
                                <div class="mt-1 text-gray-300" lang="ru">{{ $glossRu }}</div>
                            @endif
                            @if(!empty($token['gloss']))
                                <div class="mt-1 text-gray-400">{{ $token['gloss'] }}</div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
            <div class="text-sm italic text-gray-500" lang="sa-Latn">{{ $sentence['text'] }}</div>
        </article>
    @endforeach
</div>

@if(!empty($rankedPacks))
    <section class="max-w-3xl mx-auto mt-14">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Градуированное чтение — от простого к сложному</h2>
        <p class="text-xs text-gray-500 mb-3">
            Порядок kosha (H949), только справочно — только текст выше подключен к чтению с разбором.
        </p>
        <ol class="space-y-1.5">
            @foreach($rankedPacks as $row)
                <li class="flex items-baseline justify-between gap-4 px-3 py-2 rounded-lg {{ ($row['slug'] ?? null) === ($pack['slug'] ?? null) ? 'bg-[#161b28] border border-[#E85C24]/40' : 'bg-[#12151f]' }}">
                    <span class="text-gray-300 text-sm">
                        <span class="text-gray-600 mr-2">{{ $row['order'] }}.</span>{{ $row['title'] }}
                        @if(($row['slug'] ?? null) === ($pack['slug'] ?? null))
                            <span class="text-[#E85C24] text-xs ml-1">(эта страница)</span>
                        @endif
                    </span>
                    <span class="text-gray-500 text-xs shrink-0">{{ number_format($row['difficulty'], 3) }}</span>
                </li>
            @endforeach
        </ol>
    </section>
@endif

<div class="max-w-3xl mx-auto mt-14 pt-8 border-t border-gray-800 text-sm text-gray-500">
    Текст и разбор: <a href="https://www.sanskrit-linguistics.org/dcs/" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline">Digital Corpus of Sanskrit</a> (CC BY 4.0), через
    <a href="https://github.com/gasyoun/kosha" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline">kosha</a>. Оценка сложности —
    <a href="https://github.com/gasyoun/kosha/blob/main/data/difficulty/METHODS.md" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline">метод kosha (H949)</a>.
</div>
