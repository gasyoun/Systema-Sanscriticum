<div class="font-nunito max-w-7xl mx-auto px-4 py-8" x-data="{ viewMode: localStorage.getItem('dictionaryViewMode') || 'list' }">
    
    {{-- Заголовок --}}
    <div class="mb-8">
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] mb-3 tracking-tight">Словарь</h1>
        <p class="text-gray-500 text-lg">Ищите термины на санскрите, транслитерации или по русскому значению.</p>
    </div>

    {{-- ПАНЕЛЬ УПРАВЛЕНИЯ --}}
    <div class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 p-5 md:p-6 mb-8 relative z-20">
        
        <div class="flex flex-col md:flex-row gap-4 items-start md:items-center">
            {{-- Поиск --}}
            <div class="relative w-full md:flex-1">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input id="dictSearchInput"
                       wire:model.live.debounce.300ms="search" 
                       type="text" 
                       class="block w-full pl-11 pr-4 py-3.5 bg-gray-50 border-transparent rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all text-base font-medium" 
                       placeholder="Введите слово (например: satya, истина, सत्)...">
            </div>

            {{-- Фильтр --}}
            <div class="w-full md:w-64 shrink-0">
                <select wire:model.live="dictionary_id" 
                        class="block w-full px-4 py-3.5 bg-gray-50 border-transparent rounded-xl text-gray-700 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all font-medium appearance-none cursor-pointer">
                    <option value="all">📚 Все словари</option>
                    @foreach($dictionaries as $dict)
                        <option value="{{ $dict->id }}">{{ $dict->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Переключатель вида --}}
            <div class="hidden md:flex bg-gray-50 p-1 rounded-xl shrink-0">
                <button @click="viewMode = 'list'; localStorage.setItem('dictionaryViewMode', 'list')" :class="viewMode === 'list' ? 'bg-white shadow-sm text-[#E85C24]' : 'text-gray-400 hover:text-gray-600'" class="px-4 py-2.5 rounded-lg transition-all">
                    <i class="fas fa-list"></i>
                </button>
                <button @click="viewMode = 'grid'; localStorage.setItem('dictionaryViewMode', 'grid')" :class="viewMode === 'grid' ? 'bg-white shadow-sm text-[#E85C24]' : 'text-gray-400 hover:text-gray-600'" class="px-4 py-2.5 rounded-lg transition-all">
                    <i class="fas fa-border-all"></i>
                </button>
            </div>
        </div>

        {{-- Клавиатура --}}
        <div class="mt-4 pt-4 border-t border-gray-100 flex items-center gap-2 flex-wrap">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-widest mr-2">Символы:</span>
            @foreach(['ā','ī','ū','ṛ','ṝ','ḷ','ḹ','ṭ','ḍ','ṇ','ś','ṣ','ṃ','ḥ','ñ','ṅ'] as $char)
                <button type="button" 
                        onclick="insertSanskritChar('{{ $char }}')" 
                        class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 border border-gray-200 text-gray-700 font-medium hover:bg-[#E85C24] hover:text-white hover:border-[#E85C24] hover:shadow-md transition-all text-sm active:scale-95">
                    {{ $char }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- РЕЗУЛЬТАТЫ ПОИСКА --}}
    <div class="relative min-h-[400px]">
        
        @php
            $highlight = function($text) use ($search) {
                if (empty(trim($search))) return nl2br(e($text)); 
                $term = preg_quote(trim($search), '/');
                $escaped = e($text);
                $highlighted = preg_replace("/($term)/iu", "<mark class='bg-[#E85C24]/20 text-[#E85C24] font-bold rounded px-1'>$1</mark>", $escaped);
                return nl2br($highlighted);
            };
        @endphp

        <div wire:loading.flex style="display: none;" class="absolute inset-0 bg-white/60 backdrop-blur-sm z-10 rounded-2xl items-start justify-center pt-12">
            <div class="animate-spin rounded-full h-12 w-12 border-4 border-gray-100 border-t-[#E85C24]"></div>
        </div>

        <div :class="viewMode === 'grid' ? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6' : 'space-y-4'">
            
            @forelse($words as $word)
                <div wire:key="word-{{ $word->id }}"
                     onclick="openSanskritModal('{{ $word->id }}')" 
                     class="bg-white rounded-2xl border border-gray-100 p-5 md:p-6 hover:border-[#E85C24]/30 hover:shadow-[0_8px_30px_rgb(0,0,0,0.04)] hover:-translate-y-0.5 transition-all duration-300 group cursor-pointer flex flex-col"
                     :class="viewMode === 'list' ? 'md:flex-row md:items-center gap-6' : 'gap-4'">
                    
                    {{-- БАЗА ДАННЫХ КАРТОЧКИ --}}
                    <div id="data-{{ $word->id }}" class="hidden">
                        <div class="d-deva">{!! $highlight($word->devanagari) !!}</div>
                        <div class="d-raw-deva">{{ $word->devanagari }}</div>
                        <div class="d-iast">{!! $highlight($word->iast) !!}</div>
                        <div class="d-raw-iast">{{ $word->iast }}</div>
                        <div class="d-cyrillic">[{!! $highlight($word->cyrillic) !!}]</div>
                        <div class="d-trans">{!! $highlight($word->translation) !!}</div>
                        <div class="d-dict">{{ $word->dictionary->name ?? 'Словарь' }}</div>
                        <div class="d-page">{{ $word->page }}</div>
                    </div>

                    {{-- Левая часть --}}
                    <div class="shrink-0" :class="viewMode === 'list' ? 'md:w-[35%] md:border-r border-gray-100 md:pr-6' : 'border-b border-gray-100 pb-4'">
                        @if($word->devanagari)
                            <div class="text-3xl font-bold text-[#E3122C] mb-2 font-sanskrit">
                                {!! $highlight($word->devanagari) !!}
                            </div>
                        @endif
                        <div class="flex items-center gap-3 flex-wrap">
                            @if($word->iast)
                                <span class="text-lg font-bold text-gray-900">{!! $highlight($word->iast) !!}</span>
                            @endif
                            @if($word->cyrillic)
                                <span class="text-sm font-medium text-gray-500 bg-gray-50 px-2 py-1 rounded-md">[{!! $highlight($word->cyrillic) !!}]</span>
                            @endif
                        </div>
                    </div>

                    {{-- Правая часть --}}
                    <div class="flex-1 flex flex-col min-w-0" :class="viewMode === 'list' ? '' : 'flex-grow'">
                        <div class="text-gray-700 text-base leading-relaxed line-clamp-3 mb-4 flex-grow">
                            {!! $highlight($word->translation) !!}
                        </div>
                        
                        <div class="mt-auto flex items-center justify-between pt-2">
                            <div class="text-[11px] font-bold uppercase tracking-widest text-gray-400 flex items-center">
                                <i class="fas fa-book-open mr-2 opacity-50"></i>
                                <span class="truncate max-w-[150px]">{{ $word->dictionary->name ?? 'Словарь' }}</span>
                            </div>

                            <div class="flex gap-2 relative">
                                @if($word->devanagari)
                                    <button type="button" onclick="triggerPlay('{{ $word->id }}'); event.stopPropagation();" class="w-8 h-8 rounded-full bg-gray-50 text-gray-400 hover:bg-blue-50 hover:text-blue-500 flex items-center justify-center transition-colors" title="Послушать">
                                        <i class="fas fa-volume-up text-sm"></i>
                                    </button>
                                @endif
                                
                                <button type="button" onclick="triggerCopy('{{ $word->id }}', this); event.stopPropagation();" class="w-8 h-8 rounded-full bg-gray-50 text-gray-400 hover:bg-green-50 hover:text-green-600 flex items-center justify-center transition-colors" title="Скопировать">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            @empty
                <div class="col-span-full text-center py-20 bg-white rounded-[2rem] border border-dashed border-gray-200">
                    <div class="w-20 h-20 mx-auto bg-gray-50 rounded-full flex items-center justify-center mb-4 text-gray-300">
                        <i class="fas fa-search text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Ничего не найдено</h3>
                    <p class="text-gray-500">Попробуйте использовать виртуальную клавиатуру или изменить запрос.</p>
                </div>
            @endforelse
        </div>

        <div class="mt-10">
            {{ $words->links() }}
        </div>
    </div>

    {{-- ========================================== --}}
    {{-- МОДАЛЬНОЕ ОКНО --}}
    {{-- ========================================== --}}
    <div id="dictModal" style="display: none;" class="fixed inset-0 z-[100] items-center justify-center p-4 sm:p-6">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeSanskritModal()"></div>

        <div class="relative bg-white w-full max-w-2xl rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <button onclick="closeSanskritModal()" class="absolute top-5 right-5 w-10 h-10 bg-gray-50 hover:bg-red-50 text-gray-400 hover:text-red-500 rounded-full flex items-center justify-center transition-colors z-20">
                <i class="fas fa-times text-lg"></i>
            </button>

            <div class="bg-gray-50 p-8 sm:p-10 text-center relative overflow-hidden">
                <div class="absolute top-0 right-0 w-40 h-40 bg-[#E85C24]/10 blur-3xl rounded-full -mr-10 -mt-10 pointer-events-none"></div>
                
                <h2 id="modal-deva-title" class="text-6xl sm:text-7xl font-bold text-[#E3122C] mb-6 font-sanskrit relative z-10"></h2>
                
                <div class="flex items-center justify-center gap-4 text-xl sm:text-2xl relative z-10">
                    <span id="modal-iast-title" class="font-bold text-gray-900"></span>
                    <span id="modal-cyrillic-title" class="text-gray-500"></span>
                </div>
            </div>

            <div class="p-8 sm:p-10 overflow-y-auto custom-scrollbar">
                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Перевод и значение</h4>
                <div id="modal-trans-body" class="text-gray-800 text-lg leading-relaxed whitespace-pre-line"></div>
            </div>

            <div class="bg-white border-t border-gray-100 p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-sm font-bold text-gray-400 flex items-center bg-gray-50 px-4 py-2 rounded-xl">
                    <i class="fas fa-book mr-2"></i>
                    <span id="modal-dict-name"></span>
                    <span id="modal-dict-page" class="ml-1"></span>
                </div>

                <div class="flex gap-3">
                    <button onclick="playSanskritAudio(window.currentModalAudioText)" class="px-5 py-2.5 bg-blue-50 hover:bg-blue-100 text-blue-600 font-bold rounded-xl transition-colors flex items-center">
                        <i class="fas fa-volume-up mr-2"></i> Слушать
                    </button>
                    <button onclick="copySanskritText(window.currentModalCopyText, this)" class="px-5 py-2.5 bg-[#E85C24]/10 hover:bg-[#E85C24]/20 text-[#E85C24] font-bold rounded-xl transition-colors flex items-center w-[160px] justify-center">
                        <i class="fas fa-copy mr-2"></i>
                        <span>Копировать</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ========================================== --}}
    {{-- СКРИПТЫ (Мозги словаря) --}}
    {{-- ========================================== --}}
    <script>
    // 1. Клавиатура
    function insertSanskritChar(char) {
        let input = document.getElementById('dictSearchInput');
        if (input) {
            input.value += char;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        }
    }

    // Вспомогательные триггеры для кнопок
    function triggerPlay(id) {
        const text = document.querySelector('#data-' + id + ' .d-raw-deva').textContent;
        playSanskritAudio(text);
    }

    function triggerCopy(id, btn) {
        let text = document.querySelector('#data-' + id + ' .d-raw-deva').textContent;
        if (!text.trim()) text = document.querySelector('#data-' + id + ' .d-raw-iast').textContent;
        copySanskritText(text, btn);
    }

    // Глобальная переменная (ЗАЩИТА ОТ БАГА CHROME)
    window.sanskritVoicePlayer = null;

    // 3. Чтение аудио (Родной браузерный API с защитой от очистки памяти)
    function playSanskritAudio(text) {
        if (!text || !text.trim()) return;
        
        let cleanText = text.replace(/<\/?[^>]+(>|$)/g, ""); 
        cleanText = cleanText.replace(/[\/\\|\[\]\-\_\(\)]/g, ' ').trim(); 

        if (cleanText === '') return;

        if (!('speechSynthesis' in window)) {
            alert('Ваш браузер не поддерживает синтез речи.');
            return;
        }

        window.speechSynthesis.cancel();

        setTimeout(() => {
            // Присваиваем глобальной переменной, чтобы браузер не стер звук
            window.sanskritVoicePlayer = new SpeechSynthesisUtterance(cleanText);
            
            const voices = window.speechSynthesis.getVoices();
            console.log(`[Звук] Найдено голосов в системе: ${voices.length}`);
            
            // Ищем хинди (hi-IN) или любой другой язык с 'hi'
            let hindiVoice = voices.find(v => v.lang.toLowerCase().includes('hi') || v.name.toLowerCase().includes('hindi'));
            
            if (hindiVoice) {
                console.log(`[Звук] Использую голос: ${hindiVoice.name}`);
                window.sanskritVoicePlayer.voice = hindiVoice;
                window.sanskritVoicePlayer.lang = hindiVoice.lang;
            } else {
                console.warn('[Звук] Голос Хинди не найден. Прошу Windows включить резервный.');
                window.sanskritVoicePlayer.lang = 'hi-IN'; // Принудительно ставим метку языка
            }

            window.sanskritVoicePlayer.rate = 0.85; 
            
            // Ловим возможные системные ошибки озвучки
            window.sanskritVoicePlayer.onerror = function(event) {
                console.error('[Звук] Ошибка движка:', event.error);
            };

            window.speechSynthesis.speak(window.sanskritVoicePlayer);
        }, 50);
    }

    // Подгрузка голосов (Нужно для первого запуска на ПК)
    if ('speechSynthesis' in window) {
        window.speechSynthesis.getVoices();
        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = function() {
                window.speechSynthesis.getVoices();
            };
        }
    }

    // 4. Копирование
    function copySanskritText(text, btnElement) {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            if (btnElement) {
                let icon = btnElement.querySelector('i');
                let span = btnElement.querySelector('span');
                if (icon) icon.className = 'fas fa-check text-green-500' + (span ? ' mr-2' : '');
                if (span) span.innerText = 'Скопировано!';
                setTimeout(() => {
                    if (icon) icon.className = 'fas fa-copy' + (span ? ' mr-2' : '');
                    if (span) span.innerText = 'Копировать';
                }, 2000);
            }
        });
    }

    // 5. Модальное окно
    function openSanskritModal(id) {
        const dataDiv = document.getElementById('data-' + id);
        if (!dataDiv) return;

        document.getElementById('modal-deva-title').innerHTML = dataDiv.querySelector('.d-deva').innerHTML;
        document.getElementById('modal-iast-title').innerHTML = dataDiv.querySelector('.d-iast').innerHTML;
        document.getElementById('modal-cyrillic-title').innerHTML = dataDiv.querySelector('.d-cyrillic').innerHTML;
        document.getElementById('modal-trans-body').innerHTML = dataDiv.querySelector('.d-trans').innerHTML;
        
        document.getElementById('modal-dict-name').innerText = dataDiv.querySelector('.d-dict').textContent;
        let page = dataDiv.querySelector('.d-page').textContent;
        document.getElementById('modal-dict-page').innerText = page ? ' • Стр. ' + page : '';

        window.currentModalAudioText = dataDiv.querySelector('.d-raw-deva').textContent;
        window.currentModalCopyText = dataDiv.querySelector('.d-raw-deva').textContent || dataDiv.querySelector('.d-raw-iast').textContent;

        document.getElementById('dictModal').style.display = 'flex';
        document.body.classList.add('overflow-hidden');
    }

    function closeSanskritModal() {
        document.getElementById('dictModal').style.display = 'none';
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") closeSanskritModal();
    });
</script>
</div>