<div class="font-nunito max-w-7xl mx-auto px-4 py-8">
    
    {{-- Заголовок --}}
    <div class="mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-3 tracking-tight">Библиотека словарей</h1>
        <p class="text-gray-500 text-lg">Ищите термины на санскрите, транслитерации или по русскому значению.</p>
        <div class="w-16 h-1.5 bg-[#E85C24] rounded-full mt-4"></div>
    </div>

    {{-- Панель управления (Поиск и Фильтры) --}}
    <div class="bg-white rounded-[1.5rem] shadow-sm border border-gray-100 p-6 mb-8 flex flex-col md:flex-row gap-4 items-center relative z-20">
        
        {{-- Поиск --}}
        <div class="relative w-full md:flex-1">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <input wire:model.live.debounce.300ms="search" type="text" 
                   class="block w-full pl-11 pr-4 py-4 bg-gray-50 border-transparent rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all text-base font-medium" 
                   placeholder="Введите слово (например: satya, истина, सत्)...">
        </div>

        {{-- Фильтр по словарю --}}
        <div class="w-full md:w-64 shrink-0">
            <select wire:model.live="dictionary_id" 
                    class="block w-full px-4 py-4 bg-gray-50 border-transparent rounded-xl text-gray-700 focus:bg-white focus:border-[#E85C24] focus:ring-2 focus:ring-[#E85C24]/20 transition-all font-medium appearance-none cursor-pointer">
                <option value="all">Все словари</option>
                @foreach($dictionaries as $dict)
                    <option value="{{ $dict->id }}">{{ $dict->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Результаты (Карточки) --}}
    <div class="space-y-4 relative z-10">
        {{-- Лоадер при поиске --}}
        <div wire:loading class="absolute inset-0 bg-white/60 backdrop-blur-sm z-20 rounded-xl flex items-start justify-center pt-10">
            <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-[#E85C24]"></div>
        </div>

        @forelse($words as $word)
            <div class="bg-white rounded-2xl border border-gray-100 p-6 hover:shadow-lg transition-all duration-300 group flex flex-col md:flex-row gap-6">
                
                {{-- Санскритская часть (Слева) --}}
                <div class="md:w-1/3 shrink-0 border-b md:border-b-0 md:border-r border-gray-100 pb-4 md:pb-0 md:pr-6 flex flex-col justify-center">
                    @if($word->devanagari)
                        <div class="text-3xl font-bold text-[#E3122C] mb-2 font-sanskrit">{{ $word->devanagari }}</div>
                    @endif
                    <div class="flex items-center gap-3">
                        @if($word->iast)
                            <span class="text-lg font-bold text-gray-900">{{ $word->iast }}</span>
                        @endif
                        @if($word->cyrillic)
                            <span class="text-sm font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-md">[{{ $word->cyrillic }}]</span>
                        @endif
                    </div>
                </div>

                {{-- Перевод и описание (Справа) --}}
                <div class="flex-1 flex flex-col">
                    <div class="text-gray-700 text-base leading-relaxed flex-grow">
                        {!! nl2br(e($word->translation)) !!}
                    </div>
                    
                    {{-- Метаданные (источник) --}}
                    <div class="mt-4 pt-4 border-t border-gray-50 flex items-center justify-between text-xs font-bold uppercase tracking-widest text-gray-400">
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                            {{ $word->dictionary->name ?? 'Словарь' }}
                        </span>
                        @if($word->page)
                            <span>Стр. {{ $word->page }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-16 bg-white rounded-2xl border border-gray-100">
                <svg class="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <h3 class="text-lg font-bold text-gray-900 mb-1">Ничего не найдено</h3>
                <p class="text-gray-500">Попробуйте изменить параметры поиска или ввести слово иначе.</p>
            </div>
        @endforelse

        {{-- Пагинация --}}
        <div class="mt-8">
            {{ $words->links() }}
        </div>
    </div>
</div>