<div class="font-nunito max-w-3xl mx-auto px-4 py-8"
     x-data="{ revealed: @entangle('revealed') }"
     @keydown.window="
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
        if (event.key === ' ' || event.key === 'Enter') { if (!revealed) { event.preventDefault(); $wire.reveal(); } }
        else if (['1','2','3','4'].includes(event.key)) { if (revealed) { event.preventDefault(); $wire.grade(parseInt(event.key)); } }
     ">

    {{-- Заголовок + выбор колоды --}}
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-[#101010] tracking-tight">Карточки</h1>
            <p class="text-gray-500">Интервальные повторения — учите санскрит по чуть-чуть каждый день.</p>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            {{-- url() not route(): Livewire unit tests boot without SRS route registration --}}
            <a href="{{ url('/dvaram/srs/decks') }}"
               class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors whitespace-nowrap">
                Мои колоды
            </a>
            @if($decks->count() > 1)
                <select wire:model.live="deckId"
                        class="w-full sm:w-64 px-4 py-3 bg-gray-50 border-transparent rounded-xl text-gray-700 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all font-medium cursor-pointer">
                    @foreach($decks as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    {{-- Счётчик оставшихся --}}
    <div class="mb-4 flex items-center gap-2 text-sm font-bold text-gray-400">
        <i class="fas fa-layer-group text-[#E85C24]"></i>
        <span>Осталось на сегодня: <span class="text-gray-700">{{ $remaining }}</span></span>
    </div>

    @if($card)
        {{-- КАРТОЧКА --}}
        <div wire:key="card-{{ $card->id }}"
             class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">

            {{-- Лицо --}}
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
                @endif

                @if($audioUrl)
                    <div class="mt-6 flex justify-center">
                        <audio controls preload="metadata" class="w-full max-w-md"
                               src="{{ $audioUrl }}"
                               @if(! $revealed) autoplay @endif>
                            Ваш браузер не поддерживает аудио.
                        </audio>
                    </div>
                @endif

                @unless($revealed)
                    <p class="text-gray-400 text-sm mt-6">Нажмите <kbd class="px-2 py-0.5 bg-white border border-gray-200 rounded font-bold">Пробел</kbd>, чтобы показать ответ</p>
                @endunless
            </div>

            {{-- Ответ --}}
            @if($revealed)
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
                    <div class="text-gray-800 text-lg leading-relaxed whitespace-pre-line">{{ $f['translation'] ?? '' }}</div>
                </div>
            @endif
        </div>

        {{-- Кнопки --}}
        <div class="mt-6">
            @unless($revealed)
                <button type="button" wire:click="reveal"
                        class="w-full py-4 bg-[#E85C24] hover:bg-[#d24e1b] text-white font-bold rounded-2xl transition-colors shadow-[0_4px_15px_rgba(232,92,36,0.3)]">
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
                            <span class="text-xs font-medium opacity-70 mt-1">{{ $this->formatInterval($previews[$val] ?? 0) }}</span>
                        </button>
                    @endforeach
                </div>
            @endunless
        </div>

    @else
        {{-- Пусто: всё повторено --}}
        <div class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-gray-200">
            <div class="w-20 h-20 mx-auto bg-green-50 rounded-full flex items-center justify-center mb-4 text-green-400">
                <i class="fas fa-check text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">На сегодня всё!</h3>
            <p class="text-gray-500">Вы повторили все карточки, которые были готовы. Загляните позже.</p>
        </div>
    @endif
</div>
