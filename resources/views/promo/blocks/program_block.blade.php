<section class="py-10 lg:py-16 bg-[#F9FAFB]" x-data="{ activeModule: 0 }">
    <div class="container mx-auto px-4">
        
        {{-- Заголовок секции --}}
        <div class="max-w-3xl mx-auto text-center mb-16 flex flex-col items-center">
            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] mb-4">
                {{ $data['title'] ?? 'Программа обучения' }}
            </h2>
            <div class="w-24 h-1.5 bg-[#E85C24] rounded-full"></div>
        </div>

        @if(!empty($data['modules']))
        <div class="max-w-4xl mx-auto space-y-4">
            @foreach($data['modules'] as $index => $module)
                {{-- Карточка модуля --}}
                <div class="bg-white rounded-[2rem] overflow-hidden transition-all duration-500 border border-transparent"
                     :class="activeModule === {{ $index }} ? 'shadow-2xl shadow-orange-900/5 border-orange-100' : 'shadow-sm hover:shadow-md'">
                    
                    {{-- Заголовок (Кнопка) --}}
                    <button @click="activeModule === {{ $index }} ? activeModule = null : activeModule = {{ $index }}"
                            class="w-full flex items-center justify-between p-6 md:p-8 text-left focus:outline-none group transition-colors"
                            :class="activeModule === {{ $index }} ? 'bg-orange-50/30' : ''">
                        
                        <div class="flex items-center gap-5 md:gap-7">
                            {{-- Номер модуля --}}
                            <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 md:w-12 md:h-12 rounded-2xl text-lg font-black transition-all duration-300"
                                 :class="activeModule === {{ $index }} ? 'bg-[#E85C24] text-white rotate-6' : 'bg-gray-100 text-gray-400 group-hover:bg-orange-100 group-hover:text-[#E85C24]'">
                                {{ $index + 1 }}
                            </div>

                            <span class="text-lg md:text-2xl font-bold text-[#101010] group-hover:text-[#E85C24] transition-colors leading-tight">
                                {{ $module['module_title'] }}
                            </span>
                        </div>

                        {{-- Иконка Стрелочка (более современно, чем +) --}}
                        <div class="w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center transition-all duration-500 shrink-0 ml-4"
                             :class="activeModule === {{ $index }} ? 'bg-[#E85C24] text-white rotate-180' : 'bg-gray-100 text-gray-400'">
                            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                    </button>

                    {{-- Содержимое (Скрытый текст) --}}
                    <div x-show="activeModule === {{ $index }}" 
                         x-collapse
                         x-cloak>
                        <div class="px-6 md:px-8 pb-8 pt-2">
                            {{-- Внутренняя обертка для стилизации списков --}}
                            <div class="text-gray-600 text-base md:text-lg leading-relaxed
                                        [&>ul]:list-none [&>ul]:p-0 [&>ul]:m-0 
                                        [&>ul>li]:relative [&>ul>li]:pl-10 [&>ul>li]:mb-4 
                                        [&>ul>li]:before:absolute [&>ul>li]:before:left-0 [&>ul>li]:before:top-1 
                                        [&>ul>li]:before:flex [&>ul>li]:before:items-center [&>ul>li]:before:justify-center
                                        [&>ul>li]:before:w-6 [&>ul>li]:before:h-6 [&>ul>li]:before:bg-orange-100 
                                        [&>ul>li]:before:text-[#E85C24] [&>ul>li]:before:content-['✓'] 
                                        [&>ul>li]:before:rounded-lg [&>ul>li]:before:font-black [&>ul>li]:before:text-sm">
                                {!! $module['module_content'] !!}
                            </div>
                            
                            {{-- Дополнительная плашка (если нужно выделить итог модуля) --}}
                            @if(!empty($module['module_footer']))
                            <div class="mt-6 p-4 rounded-2xl bg-gray-50 border border-gray-100 text-sm font-bold text-gray-500 flex items-center gap-3">
                                <span class="w-2 h-2 rounded-full bg-[#E85C24]"></span>
                                {{ $module['module_footer'] }}
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif
        
    </div>
</section>

<style>
    [x-cloak] { display: none !important; }
</style>