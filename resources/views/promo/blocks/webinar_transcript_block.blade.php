@php
    $d = $data ?? [];

    // --- YouTube → embed-id (regex как в lesson.blade.php / video_block) ---
    $ytId = null;
    $rawYt = $d['youtube_url'] ?? null;
    if (!empty($rawYt)) {
        // watch?v= / youtu.be / embed / v / live / shorts
        if (preg_match('/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|(?:v|e|embed|live|shorts)\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i', $rawYt, $m)) {
            $ytId = $m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($rawYt))) {
            $ytId = trim($rawYt);
        }
    }

    // --- RuTube → embed-id ---
    $rtId = null;
    $rawRt = $d['rutube_url'] ?? null;
    if (!empty($rawRt)) {
        if (preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9_-]+)/i', $rawRt, $m)) {
            $rtId = $m[1];
        }
    }

    $sentences = \App\Support\TranscriptParser::sentencesFromPublicFile($d['transcript_file'] ?? null);

    $hasVideo = $ytId || $rtId;

    // По умолчанию открываем плеер, с которого делалась стенограмма (точное совпадение),
    // если он есть; иначе — единственный доступный.
    $syncSource = ($d['sync_source'] ?? 'youtube') === 'rutube' ? 'rutube' : 'youtube';
    $available = ['rutube' => (bool) $rtId, 'youtube' => (bool) $ytId];
    $defaultPlayer = ($available[$syncSource] ?? false)
        ? $syncSource
        : ($rtId ? 'rutube' : ($ytId ? 'youtube' : 'none'));

    // Ключ localStorage для запоминания позиции/плеера (привязан к конкретным видео).
    $storageKey = 'wt-pos:'.md5(($ytId ?? '').'|'.($rtId ?? ''));

    // --- Главы (кликабельные таймкоды справа). Источники: список (быстрый ввод) + репитер. ---
    $rawChapters = [];

    // Список: по строке на главу — "таймкод пробел название" (m:ss / h:mm:ss).
    foreach (preg_split('/\r\n|\r|\n/', (string) ($d['chapters_text'] ?? '')) as $line) {
        if (preg_match('/^\s*(\d{1,2}(?::\d{2}){1,2})\s+(.+?)\s*$/u', $line, $mm)) {
            $rawChapters[] = ['time' => $mm[1], 'title' => $mm[2]];
        }
    }

    // Репитер (по одной).
    foreach ((array) ($d['chapters'] ?? []) as $ch) {
        $rawChapters[] = ['time' => trim($ch['time'] ?? ''), 'title' => trim($ch['title'] ?? '')];
    }

    $chapters = [];
    foreach ($rawChapters as $ch) {
        if ($ch['time'] === '' || $ch['title'] === '') {
            continue;
        }
        $sec = 0;
        $mult = 1;
        foreach (array_reverse(explode(':', $ch['time'])) as $part) {
            $sec += ((int) $part) * $mult;
            $mult *= 60;
        }
        $chapters[] = ['start' => $sec, 'time' => $ch['time'], 'title' => $ch['title']];
    }
    usort($chapters, fn ($a, $b) => $a['start'] <=> $b['start']);
@endphp

@if($hasVideo || !empty($sentences))
<section class="py-8 bg-white">
    <div class="container mx-auto px-4">

        @if(!empty($d['title']))
        @php
            // Подсветка: фрагменты в *звёздочках* красим акцентным цветом (как в hero-блоке).
            $titleHtml = preg_replace('/\*(.+?)\*/u', '<span style="color:#E85C24;">$1</span>', e($d['title']));
        @endphp
        <div class="text-center mb-5">
            <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">{!! $titleHtml !!}</h2>
            <div class="w-24 h-1.5 bg-[#E85C24] mx-auto mt-4 rounded-full opacity-80"></div>
        </div>
        @endif

        <div class="max-w-6xl mx-auto"
             x-data="webinarTranscript({
                 player: '{{ $defaultPlayer }}',
                 storageKey: '{{ $storageKey }}',
             })"
             x-init="init()">

            {{-- ВИДЕО --}}
            @if($hasVideo)
            {{-- Переключатель плеера наверху блока (если заданы оба источника) --}}
            @if($rtId && $ytId)
            <div class="flex gap-2 justify-center mb-3">
                <button type="button" @click="player = 'rutube'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold border transition-colors"
                        :class="player === 'rutube' ? 'bg-[#E85C24] text-white border-[#E85C24]' : 'bg-white text-gray-700 border-gray-200 hover:border-[#E85C24] hover:text-[#E85C24]'">RuTube</button>
                <button type="button" @click="player = 'youtube'"
                        class="px-4 py-1.5 rounded-full text-sm font-bold border transition-colors"
                        :class="player === 'youtube' ? 'bg-[#E85C24] text-white border-[#E85C24]' : 'bg-white text-gray-700 border-gray-200 hover:border-[#E85C24] hover:text-[#E85C24]'">YouTube</button>
            </div>
            @endif

            <div class="wt-video relative rounded-2xl overflow-hidden shadow-xl border border-gray-100 bg-black">
                @if($rtId)
                <iframe x-ref="rtPlayer" x-show="player === 'rutube'"
                        src="https://rutube.ru/play/embed/{{ $rtId }}"
                        class="absolute inset-0 w-full h-full" frameborder="0"
                        allow="autoplay; encrypted-media; fullscreen; picture-in-picture;" allowfullscreen></iframe>
                @endif
                @if($ytId)
                <iframe x-ref="ytPlayer" x-show="player === 'youtube'"
                        src="https://www.youtube.com/embed/{{ $ytId }}?enablejsapi=1&rel=0"
                        class="absolute inset-0 w-full h-full" frameborder="0"
                        allow="autoplay; encrypted-media; fullscreen; picture-in-picture;" allowfullscreen></iframe>
                @endif
            </div>
            @endif

            {{-- СОДЕРЖАНИЕ + СТЕНОГРАММА (две колонки одинаковой высоты) --}}
            @if(!empty($sentences) || !empty($chapters))
            <div class="wt-cols mt-3 flex flex-col lg:flex-row gap-4">

            {{-- ГЛАВЫ (слева) --}}
            @if(!empty($chapters))
            <div class="wt-aside order-1 w-full flex flex-col bg-white rounded-3xl shadow-xl border border-gray-200 overflow-hidden">
                <div class="wt-head px-4 py-3 shrink-0">
                    <p class="text-xs font-extrabold uppercase tracking-wider text-white flex items-center gap-2">
                        <i class="fas fa-list-ul"></i> Содержание
                    </p>
                </div>
                <div class="wt-scroll p-2 space-y-0.5 custom-scrollbar">
                    @foreach($chapters as $ch)
                        <button type="button" @click="seekTo({{ $ch['start'] }})" data-start="{{ $ch['start'] }}"
                                class="chapter-link flex items-start gap-2 w-full text-left p-2.5 rounded-xl hover:bg-gray-50 transition-colors">
                            <span class="font-mono text-[11px] font-extrabold text-[#E85C24] bg-orange-50 rounded px-1.5 py-0.5 shrink-0">{{ $ch['time'] }}</span>
                            <span class="ch-title text-[13px] text-gray-700 leading-snug">{{ $ch['title'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- СТЕНОГРАММА (справа) --}}
            @if(!empty($sentences))
            <div class="order-2 flex-1 min-w-0 w-full flex flex-col bg-white rounded-3xl shadow-xl border border-gray-200 overflow-hidden">

                {{-- Акцентная шапка --}}
                <div class="wt-head px-4 py-3 flex items-center justify-between gap-3 shrink-0">
                    <p class="text-xs font-extrabold uppercase tracking-wider text-white flex items-center gap-2">
                        <i class="fas fa-align-left"></i> Стенограмма
                    </p>
                    <button @click="autoScroll = !autoScroll"
                            class="h-7 px-2.5 rounded-lg border flex items-center gap-1.5 text-[11px] font-bold transition-colors shrink-0"
                            :class="autoScroll ? 'bg-white text-[#E85C24] border-white' : 'bg-transparent text-white border-white hover:bg-white hover:text-[#E85C24]'"
                            title="Автопрокрутка: лента сама подсвечивает текущую фразу и прокручивается вслед за видео. Нажмите, чтобы включить или выключить.">
                        <i class="fas fa-location-arrow text-xs" :class="{'animate-pulse': autoScroll}"></i>
                        <span x-text="autoScroll ? 'Автопрокрутка вкл' : 'Автопрокрутка выкл'"></span>
                    </button>
                </div>

                {{-- Дисклеймер + поиск --}}
                <div class="p-3 flex flex-col gap-2.5 border-b border-gray-100 shrink-0 bg-gray-50">
                    <p class="flex items-start gap-2 text-[12px] leading-snug text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        <i class="fas fa-triangle-exclamation mt-0.5 shrink-0 text-amber-500"></i>
                        <span>Стенограмма создана автоматически и <strong>не выверена автором</strong> — возможны неточности и ошибки в распознавании.</span>
                    </p>

                    <div class="relative w-full">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" x-model="searchQuery" @input.debounce.250ms="onSearch()" placeholder="Поиск фразы..."
                               class="w-full pl-9 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-[#E85C24] outline-none transition-all placeholder-gray-400 shadow-inner">
                    </div>
                </div>

                {{-- Лента предложений. Статичный DOM + x-ignore: Alpine НЕ обходит 1052 строки,
                     подсветка активной строки и поиск делаются императивно (см. webinarTranscript). --}}
                <div x-ref="scrollContainer"
                     class="wt-scroll relative bg-white"
                     @click="onLineClick($event)"
                     @wheel="autoScroll = false" @touchstart="autoScroll = false">
                    <div x-ignore class="p-3 space-y-1">
                        @foreach($sentences as $sentence)
                            <button type="button"
                                    data-start="{{ $sentence['start'] }}"
                                    class="transcript-line block w-full text-left p-3 rounded-r-xl transition-colors duration-200 group border-l-4 border-transparent text-gray-600 font-medium hover:bg-gray-50">
                                <span class="ts-badge inline-flex items-center gap-1.5 bg-white border border-gray-200 text-gray-400 text-[10px] font-mono font-extrabold px-2 py-0.5 rounded mr-2 shadow-sm group-hover:border-[#E85C24] group-hover:text-[#E85C24] transition-colors">{{ $sentence['formatted_time'] }}</span><span class="ts-text text-[14px] leading-relaxed">{{ $sentence['text'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            </div>
            @endif

        </div>
    </div>
</section>

@once
{{-- Активное состояние — обычный CSS (не Tailwind-утилиты), чтобы не зависеть от пересборки
     фронта и подхвата произвольных классов из JS. Специфичность .transcript-line.is-active-sentence
     (0,2,0) перебивает базовые утилиты строки (0,1,0). --}}
<style>
    /* Раскладка/акценты заданы здесь (не Tailwind-утилитами), чтобы не зависеть от пересборки фронта. */
    .wt-head { background:linear-gradient(135deg,#E85C24 0%,#f5824a 100%); min-height:3.5rem; display:flex; align-items:center; }
    .wt-video { width:100%; aspect-ratio:16/9; }
    .wt-scroll { overflow-y:auto; max-height:60vh; }
    @media (min-width:1024px) {
        /* Плеер компактный (по высоте) + колонки ниже → видео и стенограмма влезают в один экран. */
        .wt-video { height:40vh; width:auto; aspect-ratio:16/9; max-width:100%; margin-left:auto; margin-right:auto; }
        .wt-cols { height:44vh; }               /* фикс. высота ряда → колонки равной высоты */
        .wt-aside { width:23rem; flex:0 0 23rem; }  /* «Содержание» чуть шире */
        .wt-scroll { flex:1 1 auto; min-height:0; max-height:none; }  /* лента заполняет колонку */
    }

    .transcript-line.is-active-sentence { background:#F4F1EA; border-left-color:#E85C24; color:#1A1A1A; font-weight:700; }
    .transcript-line.is-active-sentence .ts-badge { background:#E85C24; border-color:#E85C24; color:#fff; }
    .chapter-link.is-active-chapter { background:#F4F1EA; }
    .chapter-link.is-active-chapter .ch-title { color:#1A1A1A; font-weight:600; }
</style>
<script>
    // Синхронизация видео↔стенограмма. Перемотка/таймкоды работают для YouTube и RuTube
    // (postMessage-протокол). Для производительности на больших стенограммах (1000+ фраз)
    // строки рендерятся статично (x-ignore), а подсветка активной строки и поиск —
    // императивные: трогаем DOM только у изменившихся элементов, без реактивных биндингов.
    function webinarTranscript(cfg) {
        return {
            player: cfg.player,            // 'rutube' | 'youtube' | 'none'
            storageKey: cfg.storageKey,
            currentTime: 0,
            autoScroll: true,
            searchQuery: '',
            lines: [],                     // [{el, start, end, text}]
            activeEl: null,
            chapters: [],                  // [{el, start}]
            activeChapterEl: null,
            pendingSeek: null,             // сохранённая позиция для восстановления после перезагрузки
            resumeTries: 0,
            lastSave: 0,

            iframeOf(name) {
                if (name === 'rutube') return this.$refs.rtPlayer || null;
                if (name === 'youtube') return this.$refs.ytPlayer || null;
                return null;
            },

            activeIframe() {
                return this.iframeOf(this.player);
            },

            // Поставить указанный плеер на паузу (скрытый iframe сам не останавливается).
            pause(name) {
                const f = this.iframeOf(name);
                if (!f || !f.contentWindow) return;
                if (name === 'youtube') {
                    f.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }), '*');
                } else if (name === 'rutube') {
                    f.contentWindow.postMessage(JSON.stringify({ type: 'player:pause', data: {} }), '*');
                }
            },

            // Сохранить позицию/плеер (с троттлингом, чтобы не писать на каждый тик).
            persist(force = false) {
                const now = Date.now();
                if (!force && now - this.lastSave < 2000) return;
                this.lastSave = now;
                try {
                    localStorage.setItem(this.storageKey, JSON.stringify({ t: this.currentTime, player: this.player }));
                } catch (e) {}
            },

            // Перемотать активный плеер на секунду sec БЕЗ запуска воспроизведения (для восстановления).
            seekRaw(sec) {
                const iframe = this.activeIframe();
                if (!iframe || !iframe.contentWindow) return;
                if (this.player === 'youtube') {
                    iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'seekTo', args: [sec, true] }), '*');
                } else if (this.player === 'rutube') {
                    iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:setCurrentTime', data: { time: sec } }), '*');
                }
            },

            init() {
                // Восстановление: вернуть выбранный плеер и позицию из прошлой сессии.
                try {
                    const saved = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                    if (saved) {
                        if (saved.player && this.iframeOf(saved.player)) this.player = saved.player;
                        if (saved.t > 1) this.pendingSeek = saved.t;
                    }
                } catch (e) {}

                // Индексируем строки один раз (start берём из data-, end = start следующей строки).
                const container = this.$refs.scrollContainer;
                const nodes = container ? Array.from(container.querySelectorAll('.transcript-line')) : [];
                this.lines = nodes.map((el, i) => ({
                    el,
                    start: parseFloat(el.dataset.start),
                    end: (i + 1 < nodes.length) ? parseFloat(nodes[i + 1].dataset.start) : Infinity,
                    textEl: el.querySelector('.ts-text'),
                    text: el.querySelector('.ts-text')?.textContent || '',
                }));

                // Главы (уже отсортированы по времени на сервере).
                this.chapters = Array.from(this.$root.querySelectorAll('.chapter-link'))
                    .map(el => ({ el, start: parseFloat(el.dataset.start) }));

                // При переключении плеера ставим предыдущий на паузу (иначе скрытый продолжает играть).
                this.$watch('player', (val, old) => {
                    if (old && old !== val) this.pause(old);
                    this.persist(true);
                });

                window.addEventListener('message', (event) => {
                    const iframe = this.activeIframe();
                    if (!iframe || event.source !== iframe.contentWindow) return;
                    try {
                        let data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                        if (data.type === 'player:currentTime') { this.onTime(data.data.time); }
                        if (data.event === 'infoDelivery' && data.info && data.info.currentTime !== undefined) { this.onTime(data.info.currentTime); }
                    } catch (e) {}
                });

                // Запрос текущего времени у активного плеера.
                setInterval(() => {
                    const iframe = this.activeIframe();
                    if (!iframe || !iframe.contentWindow) return;
                    if (this.player === 'youtube') {
                        iframe.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1 }), '*');
                    } else if (this.player === 'rutube') {
                        iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:getCurrentTime', data: {} }), '*');
                    }
                }, 500);

                // Автоскролл к активной фразе — работает и в свёрнутом, и в раскрытом виде.
                setInterval(() => {
                    if (!this.autoScroll || this.searchQuery !== '' || !this.activeEl) return;
                    const container = this.$refs.scrollContainer;
                    if (!container) return;
                    const target = this.activeEl.offsetTop - (container.clientHeight / 2) + (this.activeEl.clientHeight / 2);
                    if (Math.abs(container.scrollTop - target) > 40) {
                        container.scrollTo({ top: target, behavior: 'smooth' });
                    }
                }, 800);
            },

            // Новая позиция плеера → найти активную строку и подсветить только её.
            onTime(t) {
                // Восстановление позиции после перезагрузки: дожимаем перемотку,
                // пока плеер не окажется около сохранённой секунды (или не выйдет лимит попыток).
                if (this.pendingSeek != null) {
                    if (Math.abs(t - this.pendingSeek) <= 2) {
                        this.pendingSeek = null;          // достигли цели
                    } else if (this.resumeTries < 10) {
                        this.resumeTries++;
                        this.seekRaw(this.pendingSeek);
                        return;                           // ждём следующего тика
                    } else {
                        this.pendingSeek = null;          // сдаёмся
                    }
                }

                this.currentTime = t;
                this.persist();
                let found = null;
                for (const ln of this.lines) {
                    if (t >= ln.start && t < ln.end) { found = ln.el; break; }
                }
                if (found !== this.activeEl) {
                    if (this.activeEl) this.setActive(this.activeEl, false);
                    if (found) this.setActive(found, true);
                    this.activeEl = found;
                }

                // Активная глава = последняя, чьё начало уже прошло.
                if (this.chapters.length) {
                    let ch = null;
                    for (const c of this.chapters) {
                        if (t >= c.start) ch = c.el; else break;
                    }
                    if (ch !== this.activeChapterEl) {
                        if (this.activeChapterEl) this.activeChapterEl.classList.remove('is-active-chapter');
                        if (ch) ch.classList.add('is-active-chapter');
                        this.activeChapterEl = ch;
                    }
                }
            },

            setActive(el, on) {
                // Один класс — всё остальное делает <style> выше.
                el.classList.toggle('is-active-sentence', on);
            },

            onLineClick(e) {
                const btn = e.target.closest('.transcript-line');
                if (btn) this.seekTo(parseFloat(btn.dataset.start));
            },

            // Поиск: прячем не совпавшие строки и подсвечиваем совпадения <mark> (только у видимых).
            onSearch() {
                const q = this.searchQuery.trim().toLowerCase();
                this.autoScroll = (q === '');
                for (const ln of this.lines) {
                    const hit = q === '' || ln.text.toLowerCase().includes(q);
                    ln.el.style.display = hit ? '' : 'none';
                    if (!ln.textEl) continue;
                    if (q === '') {
                        ln.textEl.textContent = ln.text;          // сброс подсветки
                    } else if (hit) {
                        ln.textEl.innerHTML = this.mark(ln.text, q);
                    }
                }
            },

            mark(text, q) {
                const safe = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const esc = text.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
                return esc.replace(new RegExp('(' + safe + ')', 'gi'),
                    '<mark class="bg-yellow-300 text-yellow-900 rounded px-1 shadow-sm font-bold">$1</mark>');
            },

            seekTo(sec) {
                this.autoScroll = false;
                setTimeout(() => { if (this.searchQuery === '') this.autoScroll = true; }, 3000);

                let iframe = this.activeIframe();
                if (!iframe || !iframe.contentWindow) return;

                if (this.player === 'youtube') {
                    iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'seekTo', args: [sec, true] }), '*');
                    iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*');
                } else if (this.player === 'rutube') {
                    iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:setCurrentTime', data: { time: sec } }), '*');
                    iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:play', data: {} }), '*');
                }
            },
        };
    }
</script>
@endonce
@endif
