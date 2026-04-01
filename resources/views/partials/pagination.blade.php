@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Навигация по страницам" class="flex items-center justify-center space-x-2">
        
        {{-- Кнопка "Назад" --}}
        @if ($paginator->onFirstPage())
            <span class="px-4 py-2.5 text-sm font-medium text-slate-600 bg-[#111622]/50 border border-[#1F2636] rounded-xl cursor-not-allowed">
                <i class="fas fa-chevron-left mr-1.5 text-xs"></i> Назад
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-4 py-2.5 text-sm font-medium text-slate-300 bg-[#111622] border border-[#1F2636] rounded-xl hover:bg-[#1F2636] hover:text-white hover:border-[#E85C24]/50 transition-all duration-300 shadow-sm hover:shadow-[0_0_15px_rgba(232,92,36,0.15)]">
                <i class="fas fa-chevron-left mr-1.5 text-xs"></i> Назад
            </a>
        @endif

        {{-- Номера страниц (скрываем на мобилках, оставляем только Назад/Вперед) --}}
        <div class="hidden sm:flex space-x-2">
            @foreach ($elements as $element)
                
                {{-- Троеточие --}}
                @if (is_string($element))
                    <span class="px-4 py-2.5 text-sm font-medium text-slate-500 bg-[#111622]/50 border border-[#1F2636] rounded-xl">
                        {{ $element }}
                    </span>
                @endif

                {{-- Массив ссылок --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            {{-- Активная страница --}}
                            <span class="px-4 py-2.5 text-sm font-bold text-white bg-[#E85C24] border border-[#E85C24] rounded-xl shadow-[0_0_15px_rgba(232,92,36,0.4)]" aria-current="page">
                                {{ $page }}
                            </span>
                        @else
                            {{-- Обычная страница --}}
                            <a href="{{ $url }}" class="px-4 py-2.5 text-sm font-medium text-slate-400 bg-[#111622] border border-[#1F2636] rounded-xl hover:bg-[#1F2636] hover:text-white hover:border-[#E85C24]/50 transition-all duration-300">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        {{-- Кнопка "Вперед" --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-4 py-2.5 text-sm font-medium text-slate-300 bg-[#111622] border border-[#1F2636] rounded-xl hover:bg-[#1F2636] hover:text-white hover:border-[#E85C24]/50 transition-all duration-300 shadow-sm hover:shadow-[0_0_15px_rgba(232,92,36,0.15)]">
                Вперед <i class="fas fa-chevron-right ml-1.5 text-xs"></i>
            </a>
        @else
            <span class="px-4 py-2.5 text-sm font-medium text-slate-600 bg-[#111622]/50 border border-[#1F2636] rounded-xl cursor-not-allowed">
                Вперед <i class="fas fa-chevron-right ml-1.5 text-xs"></i>
            </span>
        @endif
        
    </nav>
@endif