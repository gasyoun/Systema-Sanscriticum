<div class="font-nunito max-w-3xl mx-auto px-4 py-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-[#101010] tracking-tight">Мои колоды</h1>
            <p class="text-gray-500">Личные колоды для повторения. Системные колоды редактируются преподавателем.</p>
        </div>
        <a href="{{ url('/dvaram/koloda') }}"
           class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors">
            ← К повторению
        </a>
    </div>

    @if($message)
        <div class="mb-4 px-4 py-3 rounded-xl bg-green-50 text-green-700 font-medium">{{ $message }}</div>
    @endif
    @if($error)
        <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 text-red-700 font-medium">{{ $error }}</div>
    @endif

    {{-- Create deck --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
        <h2 class="text-lg font-extrabold text-gray-900 mb-3">Новая колода</h2>
        <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" wire:model="newDeckName" placeholder="Название колоды"
                   class="flex-1 px-4 py-3 bg-gray-50 border-transparent rounded-xl focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all"/>
            <button type="button" wire:click="createDeck"
                    class="px-5 py-3 bg-[#E85C24] hover:bg-[#d24e1b] text-white font-bold rounded-xl transition-colors">
                Создать
            </button>
        </div>
    </div>

    @if($decks->isEmpty())
        <div class="text-center py-12 text-gray-400 font-medium">
            Пока нет личных колод. Создайте первую выше.
        </div>
    @else
        {{-- Deck picker --}}
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-500 mb-2">Колода</label>
            <select wire:model.live="deckId"
                    class="w-full px-4 py-3 bg-gray-50 border-transparent rounded-xl text-gray-700 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all font-medium">
                @foreach($decks as $d)
                    <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->cards_count }})</option>
                @endforeach
            </select>
        </div>

        @if($deck)
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-extrabold text-gray-900">{{ $deck->name }}</h2>
                <button type="button" wire:click="deleteDeck" wire:confirm="Удалить колоду и все её карточки?"
                        class="text-sm font-bold text-red-600 hover:text-red-700">
                    Удалить колоду
                </button>
            </div>

            {{-- Owner-editable slug (shareable /dvaram/koloda/{slug}) --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6 space-y-3">
                <h3 class="text-lg font-extrabold text-gray-900">Адрес колоды (slug)</h3>
                <p class="text-sm text-gray-500">
                    Ссылка в кабинете:
                    <code class="bg-gray-50 px-1 rounded">/dvaram/koloda/{{ $editSlug !== '' ? $editSlug : '…' }}</code>
                    — латиница, цифры и дефис.
                </p>
                <div class="flex flex-col sm:flex-row gap-3">
                    <input type="text" wire:model="editSlug" placeholder="my-vocab"
                           class="flex-1 px-4 py-3 bg-gray-50 border-transparent rounded-xl font-mono text-sm focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all"/>
                    <button type="button" wire:click="updateSlug"
                            class="px-5 py-3 bg-gray-900 hover:bg-black text-white font-bold rounded-xl transition-colors">
                        Сохранить slug
                    </button>
                </div>
            </div>

            {{-- Add card form --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6 space-y-3">
                <h3 class="text-lg font-extrabold text-gray-900">Добавить карточку</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="text" wire:model="cardDevanagari" placeholder="Деванагари"
                           class="px-4 py-3 bg-gray-50 border-transparent rounded-xl focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20"/>
                    <input type="text" wire:model="cardIast" placeholder="IAST"
                           class="px-4 py-3 bg-gray-50 border-transparent rounded-xl focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20"/>
                    <input type="text" wire:model="cardCyrillic" placeholder="Кириллица"
                           class="px-4 py-3 bg-gray-50 border-transparent rounded-xl focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20"/>
                </div>
                <textarea wire:model="cardTranslation" rows="2" placeholder="Перевод *"
                          class="w-full px-4 py-3 bg-gray-50 border-transparent rounded-xl focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20"></textarea>
                <button type="button" wire:click="addCard"
                        class="w-full sm:w-auto px-5 py-3 bg-[#E85C24] hover:bg-[#d24e1b] text-white font-bold rounded-xl transition-colors">
                    Добавить
                </button>
            </div>

            {{-- Bulk paste --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6 space-y-3">
                <h3 class="text-lg font-extrabold text-gray-900">Вставить списком</h3>
                <p class="text-sm text-gray-500">Одна карточка на строку: <code class="bg-gray-50 px-1 rounded">devanagari | iast | cyrillic | перевод</code> или <code class="bg-gray-50 px-1 rounded">лицо | перевод</code>.</p>
                <textarea wire:model="pasteBulk" rows="6" placeholder="सत्य | satya | сатья | истина"
                          class="w-full px-4 py-3 bg-gray-50 border-transparent rounded-xl font-mono text-sm focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20"></textarea>
                <button type="button" wire:click="importPaste"
                        class="px-5 py-3 bg-gray-900 hover:bg-black text-white font-bold rounded-xl transition-colors">
                    Вставить
                </button>
            </div>

            {{-- Card list --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 font-bold text-gray-700">
                    Карточки ({{ $cards->count() }}{{ $cards->count() >= 100 ? '+' : '' }})
                </div>
                @forelse($cards as $card)
                    @php $f = $card->fields ?? []; @endphp
                    <div wire:key="card-{{ $card->id }}" class="px-5 py-3 border-b border-gray-50 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-bold text-gray-900 truncate">
                                {{ $f['devanagari'] ?? '' }}
                                @if(!empty($f['iast']))
                                    <span class="text-gray-500 font-medium">{{ $f['iast'] }}</span>
                                @endif
                            </div>
                            <div class="text-sm text-gray-600">{{ $f['translation'] ?? '' }}</div>
                        </div>
                        <button type="button" wire:click="deleteCard({{ $card->id }})"
                                class="shrink-0 text-sm font-bold text-red-500 hover:text-red-700">
                            ✕
                        </button>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-gray-400">Карточек пока нет.</div>
                @endforelse
            </div>
        @endif
    @endif
</div>
