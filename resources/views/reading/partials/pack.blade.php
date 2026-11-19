{{--
    H2110 — the reading-pack body, extracted verbatim from reading/kosha-demo.blade.php so
    the public demo page and the in-cabinet cohort route render the SAME markup from the
    SAME feed shape instead of drifting into two readers.

    Contract (reading_pack_v1): $pack with slug/title/ref/text_name/source/stats/sentences[],
    $difficulty (?array, advisory) and $rankedPacks (list). Optional per-token `gloss_ru`
    ({surface, lemma}) is rendered when the feed carries it — hitopadesa-0 does, nala-1 does
    not, so the public page's output is unchanged by its introduction.

    H2111 adds the add-to-SRS half of the tap panel behind an OPT-IN flag, $srsAdd, passed
    only by ReadingPackController::cabinetShow. The public /reading/kosha-demo page passes
    nothing, so it keeps rendering exactly as before — no button, no POST target, no
    per-token form. A token with no `lemma` in the feed gets no button at all (the reader
    degrades rather than requiring the H1463 cascade lemmatizer).
--}}
@php($srsAdd = $srsAdd ?? false)
<header class="text-center mb-10">
    <h1 class="text-3xl md:text-4xl font-extrabold text-brand tracking-tight">{{ $pack['title'] }}</h1>
    <p class="mt-2 text-gray-400 text-sm">{{ $pack['ref'] }} · {{ $pack['text_name'] }} · {{ $pack['source'] }}</p>
    <p class="mt-3 text-gray-500 text-xs">
        <b class="text-gray-300">{{ $pack['stats']['sentences'] }}</b> предложений ·
        <b class="text-gray-300">{{ $pack['stats']['tokens'] }}</b> слов ·
        <b class="text-gray-300">{{ $pack['stats']['linked_tokens'] }}</b> разобрано
        (<b class="text-gray-300">{{ $pack['stats']['link_rate_pct'] }}%</b>)
    </p>
    <p class="mt-4 text-gray-500 text-xs max-w-xl mx-auto">
        Нажмите на слово, чтобы увидеть лемму, форму и значение@if($srsAdd) и добавить его в свою колоду@endif.
    </p>

    {{--
        Student onboarding — «как это использовать» (MG ruling 12-11-2026: on-site guidance
        instead of an offline export). Open on the FIRST visit, dismissible, re-openable
        via the «?» link forever after. Vanilla JS + localStorage; no flag of its own —
        it lives inside the already-gated reader surfaces.
    --}}
    <div class="max-w-2xl mx-auto mt-4 text-left" data-reader-help>
        <button type="button" data-reader-help-toggle aria-expanded="false"
            class="text-xs text-[#2AABEE] hover:underline">? Как пользоваться ридером</button>
        <div data-reader-help-panel class="hidden mt-3 rounded-2xl border border-gray-700/60 bg-[#161b28] p-5 text-sm text-gray-400 space-y-2.5">
            <p><b class="text-gray-200">Текст разобран по словам.</b> Нажмите на любое слово — увидите лемму, грамматическую форму и значение.</p>
            <p><b class="text-gray-200">Два режима чтения.</b> «Пословно» показывает слова по отдельности — видно, из чего состоит фраза. «В тексте (сандхи)» показывает фразу так, как она звучит в тексте: соседние слова сливаются в стыках. Переключайте режимы и сравнивайте, как одно и то же предложение выглядит в двух формах.</p>
            @if($srsAdd)
                <p><b class="text-gray-200">Слова — в колоду.</b> Кнопка возле разобранного слова отправляет его в вашу колоду «Старт чтения» — повторяйте её в SRS, и слова из текстов останутся с вами.</p>
            @endif
            <p><b class="text-gray-200">Сложность и порядок текстов</b> на странице — справочные: они показывают, от какого текста начинать, но не меняют порядок курса.</p>
            <button type="button" data-reader-help-dismiss class="mt-1 px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:border-brand hover:text-brand text-xs">Понятно</button>
        </div>
    </div>

    {{-- H2107 — student progress panel: opt-in, same $srsAdd gate as the add-to-SRS button. --}}
    @if($srsAdd)
        <p class="mt-3 text-gray-500 text-xs">
            Разобрано слов: <b class="text-brand">{{ $lookup_percent ?? 0 }}%</b> ·
            в колоде: <b class="text-brand">{{ $unique_lemmas ?? 0 }}</b> лемм
        </p>
    @endif
</header>

@if($srsAdd && session('reading_srs_status'))
    @php($srsWord = session('reading_srs_word'))
    <div class="max-w-3xl mx-auto mb-8">
        <div class="rounded-2xl border px-5 py-4 text-sm
            @if(session('reading_srs_status') === 'added') border-brand/50 bg-[#1d1410] text-gray-200
            @else border-gray-700/60 bg-[#161b28] text-gray-400 @endif">
            @if(session('reading_srs_status') === 'added')
                Слово <b lang="sa-Latn">{{ $srsWord }}</b> добавлено в вашу колоду «Старт чтения».
                @if(config('srs.enabled'))
                    <a href="{{ route('student.srs.deck', \App\Support\StartChteniyaSrsDeck::DECK_SLUG) }}" class="text-[#2AABEE] hover:underline">Открыть колоду</a>
                @endif
            @elseif(session('reading_srs_status') === 'duplicate')
                Слово <b lang="sa-Latn">{{ $srsWord }}</b> уже есть в вашей колоде «Старт чтения».
            @else
                Для формы <b lang="sa-Latn">{{ $srsWord }}</b> в этом пакете нет леммы — карточку не создаем.
            @endif
        </div>
    </div>
@endif

{{-- ═════ Сложность (H965, Hop C — справочные данные, не влияют на порядок курса) ═════ --}}
@if($difficulty)
    <div class="max-w-3xl mx-auto mb-8">
        <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
            <div class="flex items-baseline justify-between gap-4 mb-3">
                <h2 class="text-sm font-black uppercase tracking-widest text-gray-500">Сложность текста</h2>
                <span class="text-xs text-gray-500">№{{ $difficulty['order'] }} из {{ count($rankedPacks) }} оцененных — от простого к сложному</span>
            </div>
            <div class="text-2xl font-extrabold text-brand">{{ number_format($difficulty['difficulty'], 3) }}</div>
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

{{--
    Sandhi twin (MG ruling 12-11-2026): one page-level toggle swaps the PROMINENCE of the
    two forms every diverging sentence already carries — the segmented token chips
    («Пословно», the word-tap surface) and the natural sandhied surface («В тексте»).
    Both lines stay in the DOM and tappable; only size/colour/colour order swap, so the
    morph panels and the H2107 telemetry keep working in either mode. The attribute on
    this root is the single source of truth; vanilla JS + localStorage, no flag of its
    own (rides the reader's existing gates).
--}}
<div data-sandhi-root data-sandhi-mode="split">
    <div class="max-w-3xl mx-auto mb-5 flex flex-wrap items-center justify-center gap-2 text-xs">
        <span class="text-gray-500">Режим чтения:</span>
        <button type="button" data-sandhi-mode-btn="split" aria-pressed="true"
            class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:border-brand aria-pressed:bg-[#1d1410] aria-pressed:border-brand/60 aria-pressed:text-white transition-colors">Пословно</button>
        <button type="button" data-sandhi-mode-btn="text" aria-pressed="false"
            class="px-3 py-1.5 rounded-lg border border-gray-600 text-gray-300 hover:border-brand aria-pressed:bg-[#1d1410] aria-pressed:border-brand/60 aria-pressed:text-white transition-colors">В тексте (сандхи)</button>
    </div>
    <div class="space-y-6 max-w-3xl mx-auto">
    @foreach($pack['sentences'] as $sentence)
        @php($sentenceIndex = $loop->index)
        <article class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
            <div class="text-xs uppercase tracking-widest text-gray-500 mb-2">{{ $sentence['locus'] }}</div>
            @php($surfaceLine = trim((string) ($sentence['text'] ?? '')))
            @php($splitLine = trim(implode(' ', array_map(fn ($t) => (string) ($t['form'] ?? ''), $sentence['tokens']))))
            @php($hasTwin = $surfaceLine !== '' && $surfaceLine !== $splitLine)
            <div class="flex flex-col gap-2 data-[sandhi-mode=text]:flex-col-reverse">
            <div class="deva text-xl text-white leading-relaxed transition-all data-[sandhi-mode=text]:text-sm data-[sandhi-mode=text]:text-gray-300 data-[sandhi-mode=text]:opacity-80" data-reader-tokens>
                @foreach($sentence['tokens'] as $token)
                    <details class="inline-block align-baseline mr-1 mb-1 group"
                        @if($srsAdd && !empty($token['lemma']))
                            data-lookup-track
                            data-lookup-sentence="{{ $sentenceIndex }}"
                            data-lookup-token="{{ $loop->index }}"
                        @endif
                    >
                        <summary class="inline-block cursor-pointer px-1.5 py-0.5 rounded-md bg-gray-800/70 text-gray-100 hover:bg-gray-700 hover:text-brand transition-colors marker:content-none [&::-webkit-details-marker]:hidden">{{ $token['form'] }}</summary>
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
                            @if($srsAdd && !empty($token['lemma']))
                                {{-- Positions only: the card's text is read from the pinned freeze server-side. --}}
                                <form method="POST" action="{{ route('student.reading.srs.add', $pack['slug']) }}" class="mt-2">
                                    @csrf
                                    <input type="hidden" name="sentence" value="{{ $sentenceIndex }}">
                                    <input type="hidden" name="token" value="{{ $loop->index }}">
                                    <button type="submit" class="text-xs px-2 py-1 rounded-md border border-gray-600 text-gray-300 hover:border-brand hover:text-brand transition-colors">
                                        + в колоду
                                    </button>
                                </form>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
            @if($hasTwin)
                <div class="text-sm italic text-gray-500 transition-all data-[sandhi-mode=text]:text-xl data-[sandhi-mode=text]:not-italic data-[sandhi-mode=text]:text-white" lang="sa-Latn" data-reader-sandhi>{{ $surfaceLine }}</div>
            @endif
            </div>
        </article>
    @endforeach
    </div>
</div>

@if(!empty($rankedPacks))
    <section class="max-w-3xl mx-auto mt-14">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Градуированное чтение — от простого к сложному</h2>
        <p class="text-xs text-gray-500 mb-3">
            Порядок kosha (H949), только справочно — только текст выше подключен к чтению с разбором.
        </p>
        <ol class="space-y-1.5">
            @foreach($rankedPacks as $row)
                <li class="flex items-baseline justify-between gap-4 px-3 py-2 rounded-lg {{ ($row['slug'] ?? null) === ($pack['slug'] ?? null) ? 'bg-[#161b28] border border-brand/40' : 'bg-[#12151f]' }}">
                    <span class="text-gray-300 text-sm">
                        <span class="text-gray-600 mr-2">{{ $row['order'] }}.</span>{{ $row['title'] }}
                        @if(($row['slug'] ?? null) === ($pack['slug'] ?? null))
                            <span class="text-brand text-xs ml-1">(эта страница)</span>
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

@if($srsAdd)
    {{--
        H2107 — fire-and-forget lookup telemetry. Vanilla JS, no new dependency: each
        details' first `toggle` (open only) POSTs its positions once per page load, so
        re-opening the same word repeatedly does not spam activity_events.
    --}}
    <script>
        document.querySelectorAll('details[data-lookup-track]').forEach(function (el) {
            let sent = false;
            el.addEventListener('toggle', function () {
                if (sent || !el.open) return;
                sent = true;
                fetch({{ Js::from(route('student.reading.lookup', $pack['slug'])) }}, {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': {{ Js::from(csrf_token()) }},
                    },
                    body: JSON.stringify({
                        sentence: el.dataset.lookupSentence,
                        token: el.dataset.lookupToken,
                    }),
                }).catch(function () { /* telemetry only — never surface an error to the reader */ });
            });
        });
    </script>
@endif

<script>
    {{-- Sandhi twin toggle + reader onboarding. Vanilla JS, no dependency, no server call:
         the mode and the help dismissal live in localStorage per browser. --}}
    (function () {
        var root = document.querySelector('[data-sandhi-root]');
        if (root) {
            var modeKey = 'systema_reading_sandhi_mode_v1';
            var saved = null;
            try { saved = localStorage.getItem(modeKey); } catch (e) { /* private mode — default */ }
            var apply = function (mode) {
                root.setAttribute('data-sandhi-mode', mode === 'text' ? 'text' : 'split');
                root.querySelectorAll('[data-sandhi-mode-btn]').forEach(function (btn) {
                    btn.setAttribute('aria-pressed', btn.getAttribute('data-sandhi-mode-btn') === mode ? 'true' : 'false');
                });
                try { localStorage.setItem(modeKey, mode); } catch (e) { /* no persistence, still works */ }
            };
            root.querySelectorAll('[data-sandhi-mode-btn]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    apply(btn.getAttribute('data-sandhi-mode-btn'));
                });
            });
            apply(saved === 'text' ? 'text' : 'split');
        }

        var help = document.querySelector('[data-reader-help]');
        if (help) {
            var helpKey = 'systema_reading_help_seen_v1';
            var panel = help.querySelector('[data-reader-help-panel]');
            var toggle = help.querySelector('[data-reader-help-toggle]');
            var dismiss = help.querySelector('[data-reader-help-dismiss]');
            var seen = false;
            try { seen = localStorage.getItem(helpKey) === '1'; } catch (e) { /* show it then */ }
            var show = function (open) {
                panel.classList.toggle('hidden', !open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            toggle.addEventListener('click', function () {
                show(panel.classList.contains('hidden'));
            });
            dismiss.addEventListener('click', function () {
                show(false);
                try { localStorage.setItem(helpKey, '1'); } catch (e) { /* non-critical */ }
            });
            show(!seen);
        }
    })();
</script>
