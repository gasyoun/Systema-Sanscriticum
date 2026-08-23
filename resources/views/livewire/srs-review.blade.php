<div class="font-nunito max-w-3xl mx-auto px-4 py-8"
     x-data="{
        revealed: @entangle('revealed'),
        speedSeconds: @entangle('speedSeconds'),
        mode: @js($mode),
        timer: null,
        startSpeed() {
            if (this.mode !== 'speed' || this.timer) return;
            this.timer = setInterval(() => {
                if (this.speedSeconds <= 0) {
                    clearInterval(this.timer);
                    this.timer = null;
                    $wire.speedTimeout();
                    return;
                }
                this.speedSeconds = this.speedSeconds - 1;
            }, 1000);
        },
        stopSpeed() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
        }
     }"
     x-init="if (mode === 'speed') startSpeed(); $watch('mode', v => { stopSpeed(); if (v === 'speed') startSpeed(); })"
     @keydown.window="
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
        if (mode === 'classic' || mode === 'speed' || mode === 'difficult') {
            if (event.key === ' ' || event.key === 'Enter') { if (!revealed) { event.preventDefault(); $wire.reveal(); } }
            else if (['1','2','3','4'].includes(event.key)) { if (revealed) { event.preventDefault(); $wire.grade(parseInt(event.key)); } }
        }
     ">

    @if($isGuest)
        <div class="mb-6 rounded-2xl border border-brand/30 bg-orange-50 px-4 py-3 text-sm text-gray-700">
            <span class="font-bold text-brand">Пробный режим.</span>
            Прогресс не сохраняется.
            <a href="{{ url('/login') }}" class="font-bold text-brand underline hover:no-underline">Войдите</a>
            или
            <a href="{{ url('/login') }}" class="font-bold text-brand underline hover:no-underline">зарегистрируйтесь</a>,
            чтобы учить с интервальными повторениями и сохранять результат.
        </div>
    @endif

    {{-- Заголовок + выбор колоды --}}
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-[#101010] tracking-tight">
                {{ $deck?->name ?? 'Карточки' }}
            </h1>
            <p class="text-gray-500">{{ $tagline }}</p>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            @unless($isGuest)
                {{-- url() not route(): Livewire unit tests boot without SRS route registration --}}
                <a href="{{ url('/dvaram/koloda/decks') }}"
                   class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors whitespace-nowrap">
                    Мои колоды
                </a>
            @else
                <a href="{{ url('/koloda') }}"
                   class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors whitespace-nowrap">
                    Все колоды
                </a>
            @endunless
            @if($decks->count() > 1)
                {{-- deckId теперь #[Locked] (H3313): выбор колоды навигируется
                     по URL, а не пишет Livewire-свойство из клиента. --}}
                <select x-on:change="window.location.href = $event.target.value"
                        aria-label="Выбор колоды"
                        class="w-full sm:w-64 px-4 py-3 bg-gray-50 border-transparent rounded-xl text-gray-700 focus:bg-white focus:border-brand focus:ring-2 focus:ring-brand/20 transition-all font-medium cursor-pointer">
                    @foreach($decks as $d)
                        @php
                            $deckSegment = \App\Livewire\SrsReview::deckPathSegment($d);
                            $deckUrl = url((($isGuest || request()->routeIs('srs.*')) ? '/koloda/' : '/dvaram/koloda/').$deckSegment);
                        @endphp
                        <option value="{{ $deckUrl }}" @selected((int) $d->id === (int) ($deck?->id))>{{ $d->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    @unless($isGuest)
        {{-- Mode switcher (Memrise P2) --}}
        @php
            $modeLabels = [
                'classic' => 'Классика',
                'mc' => 'Выбор',
                'typing' => 'Ввод',
                'pairs' => 'Пары',
                'speed' => 'Скорость',
                'difficult' => 'Сложные',
            ];
        @endphp
        <div class="mb-6 flex flex-wrap gap-2" role="tablist" aria-label="Режим тренировки">
            @foreach($modeLabels as $key => $label)
                <button type="button"
                        wire:click="setMode('{{ $key }}')"
                        role="tab"
                        aria-selected="{{ $mode === $key ? 'true' : 'false' }}"
                        class="px-3 py-2 rounded-xl text-sm font-bold transition-colors
                            {{ $mode === $key
                                ? 'bg-brand text-white shadow-[0_2px_10px_rgba(232,92,36,0.25)]'
                                : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    @endunless

    {{-- Счётчик --}}
    <div class="mb-4 flex items-center gap-2 text-sm font-bold text-gray-400">
        <i class="fas fa-layer-group text-brand"></i>
        @if($isGuest)
            <span>Осталось в пробе: <span class="text-gray-700">{{ $remaining }}</span></span>
        @elseif($mode === 'pairs')
            <span>Пар осталось: <span class="text-gray-700">{{ $remaining }}</span></span>
        @elseif($mode === 'difficult')
            <span>Сложных: <span class="text-gray-700">{{ $remaining }}</span></span>
        @else
            <span>Осталось на сегодня: <span class="text-gray-700">{{ $remaining }}</span></span>
        @endif

        @if($mode === 'speed' && ($card || false))
            <span class="ml-auto inline-flex items-center gap-1 text-brand"
                  x-text="'⏱ ' + speedSeconds + ' с'"></span>
        @endif
    </div>

    {{-- ===== PAIRS MODE ===== --}}
    @if($mode === 'pairs' && ! $isGuest)
        @if(count($pairLeft) === 0)
            <div class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-gray-200">
                <h3 class="text-xl font-bold text-gray-900 mb-2">Недостаточно карточек</h3>
                <p class="text-gray-500">Для режима «Пары» нужно хотя бы 2 карточки с текстом на обеих сторонах.</p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-2">Слово</p>
                    @foreach($pairLeft as $item)
                        @php $done = in_array($item['id'], $pairMatched, true); @endphp
                        <button type="button"
                                wire:click="selectPairLeft({{ $item['id'] }})"
                                @disabled($done)
                                class="w-full text-left px-4 py-3 rounded-2xl border font-bold transition-colors
                                    {{ $done ? 'opacity-40 border-green-200 bg-green-50 text-green-700' : '' }}
                                    {{ ! $done && $pairSelectedLeft === $item['id'] ? 'border-brand bg-orange-50 text-brand' : '' }}
                                    {{ ! $done && $pairSelectedLeft !== $item['id'] ? 'border-gray-100 bg-white hover:border-gray-200 text-gray-900' : '' }}">
                            {{ $item['text'] }}
                        </button>
                    @endforeach
                </div>
                <div class="space-y-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-2">Значение</p>
                    @foreach($pairRight as $item)
                        @php $done = in_array($item['id'], $pairMatched, true); @endphp
                        <button type="button"
                                wire:click="selectPairRight({{ $item['id'] }})"
                                @disabled($done)
                                class="w-full text-left px-4 py-3 rounded-2xl border font-bold transition-colors
                                    {{ $done ? 'opacity-40 border-green-200 bg-green-50 text-green-700' : 'border-gray-100 bg-white hover:border-gray-200 text-gray-900' }}">
                            {{ $item['text'] }}
                        </button>
                    @endforeach
                </div>
            </div>
            @if($pairsFeedback === 'wrong')
                <p class="mt-4 text-center text-sm font-bold text-red-600">Не пара — попробуйте ещё</p>
            @endif
        @endif

    {{-- ===== CARD MODES (classic / mc / typing / speed / difficult) ===== --}}
    @elseif($card)
        <div wire:key="card-{{ $card->id }}-{{ $mode }}"
             class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">

            <div class="p-10 sm:p-14 text-center bg-gray-50/60">
                @php
                    $f = $card->fields ?? [];
                    $audioUrl = $this->mediaUrl($f['audio'] ?? null);
                    $imageUrl = $this->mediaUrl($f['image'] ?? null);
                @endphp
                @if(!empty($f['devanagari']))
                    <div class="text-6xl sm:text-7xl font-bold text-[#E3122C] font-sanskrit mb-2">{{ $f['devanagari'] }}</div>
                    @if(!empty($f['iast']))
                        <div class="text-xl font-semibold text-gray-600 mt-1">{{ $f['iast'] }}</div>
                    @endif
                @elseif(!empty($f['iast']))
                    <div class="text-5xl font-bold text-gray-900 mb-2">{{ $f['iast'] }}</div>
                @elseif($promptText !== '')
                    <div class="text-4xl font-bold text-gray-900 mb-2">{{ $promptText }}</div>
                @endif

                @if($audioUrl)
                    <div class="mt-6 flex justify-center">
                        <audio controls preload="metadata" class="w-full max-w-md"
                               src="{{ $audioUrl }}"
                               @if(! $revealed && $mode !== 'mc' && $mode !== 'typing') autoplay @endif>
                            Ваш браузер не поддерживает аудио.
                        </audio>
                    </div>
                @endif

                @if(in_array($mode, ['classic', 'speed', 'difficult'], true) && ! $revealed)
                    <p class="text-gray-400 text-sm mt-6">Нажмите <kbd class="px-2 py-0.5 bg-white border border-gray-200 rounded font-bold">Пробел</kbd>, чтобы показать ответ</p>
                @endif
            </div>

            {{-- MC choices --}}
            @if($mode === 'mc')
                <div class="px-6 sm:px-10 py-6 border-t border-gray-100 space-y-3">
                    <p class="text-sm font-bold text-gray-400 text-center mb-2">Выберите перевод</p>
                    @foreach($mcChoices as $i => $choice)
                        <button type="button"
                                wire:click="chooseMc({{ $i }})"
                                class="w-full text-left px-5 py-4 rounded-2xl border border-gray-100 bg-white hover:border-brand hover:bg-orange-50 font-semibold text-gray-800 transition-colors">
                            {{ $choice['text'] }}
                        </button>
                    @endforeach
                    @if(count($mcChoices) === 0)
                        <p class="text-center text-gray-500 text-sm">Нужно больше карточек в колоде для вариантов ответа.</p>
                        <button type="button" wire:click="setMode('classic')"
                                class="w-full py-3 text-brand font-bold">Перейти в классику</button>
                    @endif
                </div>
            @endif

            {{-- Typing --}}
            @if($mode === 'typing')
                <div class="px-6 sm:px-10 py-6 border-t border-gray-100">
                    <label for="srs-typed" class="block text-sm font-bold text-gray-400 mb-2 text-center">Введите перевод (или IAST / кириллицу)</label>
                    <form wire:submit.prevent="submitTyping" class="space-y-3">
                        <input id="srs-typed"
                               type="text"
                               wire:model="typedAnswer"
                               autocomplete="off"
                               autocapitalize="off"
                               class="w-full px-4 py-3 rounded-2xl border border-gray-200 focus:border-brand focus:ring-2 focus:ring-brand/20 font-medium text-gray-900"
                               placeholder="ответ…">
                        <button type="submit"
                                class="w-full py-4 bg-brand hover:bg-[#d24e1b] text-white font-bold rounded-2xl transition-colors shadow-[0_4px_15px_rgba(232,92,36,0.3)]">
                            Проверить
                        </button>
                    </form>
                    <p class="text-xs text-gray-400 text-center mt-3">P2b: пока без экранной клавиатуры деванагари — принимаем транслит и кириллицу.</p>
                </div>
            @endif

            {{-- Classic / speed / difficult reveal --}}
            @if(in_array($mode, ['classic', 'speed', 'difficult'], true) && $revealed)
                <div class="px-8 sm:px-14 py-8 border-t border-gray-100 text-center">
                    @if($imageUrl)
                        <div class="mb-6 flex justify-center">
                            <img src="{{ $imageUrl }}"
                                 alt=""
                                 class="max-h-48 sm:max-h-64 rounded-2xl object-contain shadow-sm border border-gray-100"
                                 loading="lazy">
                        </div>
                    @endif
                    <div class="flex items-center justify-center gap-3 flex-wrap mb-4">
                        @if(!empty($f['iast']) && empty($f['devanagari']))
                            <span class="text-xl font-bold text-gray-900">{{ $f['iast'] }}</span>
                        @endif
                        @if(!empty($f['cyrillic']))
                            <span class="text-sm font-medium text-gray-500 bg-gray-50 px-2 py-1 rounded-md">[{{ $f['cyrillic'] }}]</span>
                        @endif
                    </div>
                    <div class="text-gray-800 text-lg leading-relaxed whitespace-pre-line">{{ $answerText !== '' ? $answerText : ($f['translation'] ?? '') }}</div>
                </div>
            @endif
        </div>

        @if(in_array($mode, ['classic', 'speed', 'difficult'], true))
            <div class="mt-6">
                @unless($revealed)
                    <button type="button" wire:click="reveal"
                            class="w-full py-4 bg-brand hover:bg-[#d24e1b] text-white font-bold rounded-2xl transition-colors shadow-[0_4px_15px_rgba(232,92,36,0.3)]">
                        Показать ответ
                    </button>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @php
                            $buttons = [
                                1 => ['Не помню', 'bg-red-50 text-red-600 hover:bg-red-100', 'border-red-200'],
                                2 => ['Трудно', 'bg-orange-50 text-orange-600 hover:bg-orange-100', 'border-orange-200'],
                                3 => ['Хорошо', 'bg-green-50 text-green-600 hover:bg-green-100', 'border-green-200'],
                                4 => ['Легко', 'bg-blue-50 text-blue-600 hover:bg-blue-100', 'border-blue-200'],
                            ];
                        @endphp
                        @foreach($buttons as $val => $b)
                            <button type="button" wire:click="grade({{ $val }})"
                                    class="flex flex-col items-center py-3 px-2 rounded-2xl border {{ $b[2] }} {{ $b[1] }} font-bold transition-colors">
                                <span class="text-[10px] font-bold text-gray-400 mb-1">{{ $val }}</span>
                                <span>{{ $b[0] }}</span>
                                @unless($isGuest)
                                    <span class="text-xs font-medium opacity-70 mt-1">{{ $this->formatInterval($previews[$val] ?? 0) }}</span>
                                @endunless
                            </button>
                        @endforeach
                    </div>
                @endunless
            </div>
        @endif

    @elseif($guestLimitReached)
        <div class="text-center py-16 bg-white rounded-[2rem] border border-dashed border-brand/40 px-6">
            <div class="w-20 h-20 mx-auto bg-orange-50 rounded-full flex items-center justify-center mb-4 text-brand">
                <i class="fas fa-lock text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">Пробные карточки закончились</h3>
            <p class="text-gray-500 mb-6 max-w-md mx-auto">
                Зарегистрируйтесь бесплатно — прогресс сохранится, и интервальные повторения подстроятся под вас.
            </p>
            <a href="{{ url('/login') }}"
               class="inline-flex items-center justify-center px-8 py-4 bg-brand hover:bg-[#d24e1b] text-white font-bold rounded-2xl transition-colors shadow-[0_4px_15px_rgba(232,92,36,0.3)]">
                Войти или зарегистрироваться
            </a>
        </div>
    @else
        <div class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-gray-200">
            <div class="w-20 h-20 mx-auto bg-green-50 rounded-full flex items-center justify-center mb-4 text-green-400">
                <i class="fas fa-check text-3xl"></i>
            </div>
            @if($mode === 'difficult')
                <h3 class="text-xl font-bold text-gray-900 mb-2">Сложных пока нет</h3>
                <p class="text-gray-500">Карточки попадают сюда после ошибок (lapses &gt; 0). Повторите колоду в классике.</p>
            @else
                <h3 class="text-xl font-bold text-gray-900 mb-2">На сегодня всё!</h3>
                <p class="text-gray-500">Вы повторили все карточки, которые были готовы. Загляните позже.</p>
            @endif
        </div>
    @endif
</div>
