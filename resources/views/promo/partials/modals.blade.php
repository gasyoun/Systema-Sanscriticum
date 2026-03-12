{{-- 1. МОДАЛКА ПРОСМОТРА ДОКУМЕНТОВ (PDF) --}}
<div x-show="openDoc" 
     style="display: none;"
     class="fixed inset-0 z-[60] flex items-center justify-center p-2 sm:p-4"
     role="dialog" 
     aria-modal="true">
    
    {{-- Затемнение фона --}}
    <div x-show="openDoc" @click="openDoc = false" x-transition.opacity class="fixed inset-0 bg-black/80 backdrop-blur-sm"></div>

    {{-- Само окно --}}
    <div x-show="openDoc" x-transition.scale.95 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-[90vh] flex flex-col overflow-hidden z-10">
        
        {{-- Шапка окна --}}
        <div class="flex items-center justify-between p-4 sm:p-6 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg sm:text-xl font-bold text-gray-900" x-text="docTitle"></h3>
            <button @click="openDoc = false" class="text-gray-400 hover:text-gray-600 transition p-2 rounded-full hover:bg-gray-200 bg-white shadow-sm">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Тело окна (Iframe) --}}
        <div class="flex-1 w-full bg-gray-200 relative">
            <div class="absolute inset-0 flex items-center justify-center text-gray-400">
                <i class="fas fa-spinner fa-spin text-3xl"></i>
            </div>
            <template x-if="openDoc">
                <iframe :src="docUrl" class="w-full h-full relative z-10 border-0"></iframe>
            </template>
        </div>

        {{-- Подвал окна --}}
        <div class="p-4 border-t border-gray-100 bg-white text-right flex justify-between items-center">
            <a :href="docUrl" target="_blank" class="text-sm text-indigo-600 hover:underline flex items-center">
                <i class="fas fa-external-link-alt mr-2"></i> Открыть в новой вкладке
            </a>
            <button @click="openDoc = false" class="px-6 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800 transition shadow-md">
                Закрыть
            </button>
        </div>
    </div>
</div>


{{-- 2. МОДАЛКА СОГЛАСИЯ (ПЕРЕД ПЕРЕХОДОМ В TELEGRAM) --}}
<div x-show="openConsent" 
     style="display: none;"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     role="dialog" 
     aria-modal="true">
    
    {{-- Затемнение --}}
    <div x-show="openConsent" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="openConsent = false" class="fixed inset-0 bg-black/80 backdrop-blur-md"></div>

    {{-- Окно --}}
    <div x-show="openConsent" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-90 translate-y-4" class="relative bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 sm:p-8 text-center z-10">
        
        <div class="mb-6">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-orange-100 mb-4">
                <svg class="h-6 w-6 text-[#E85C24]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900">Подтверждение</h3>
            <p class="text-sm text-gray-500 mt-2">Для продолжения необходимо ваше согласие с условиями.</p>
        </div>

        <div class="space-y-3 mb-8" x-data="{ localAgreed: true, localPromo: true }">
            
            {{-- Чекбокс 1 --}}
            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                <div class="flex items-center h-5 mt-0.5 shrink-0">
                    <input type="checkbox" x-model="localAgreed" @change="agreed = localAgreed" class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                </div>
                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                    Я даю <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на обработку персональных данных.
                </div>
            </label>

            {{-- Чекбокс 2 --}}
            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                <div class="flex items-center h-5 mt-0.5 shrink-0">
                    <input type="checkbox" x-model="localPromo" class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                </div>
                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                    Я даю <span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие на получение рассылки</span>.
                </div>
            </label>

            <div class="grid gap-3 mt-6">
                {{-- Кнопка Продолжить --}}
                <button @click="if(localAgreed) { agreed = true; proceed(); }" 
                        :disabled="!localAgreed"
                        :class="localAgreed ? 'bg-[#E85C24] hover:bg-[#d04a15] shadow-lg shadow-orange-500/30' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                        class="w-full text-white font-bold py-3.5 rounded-xl transition-all duration-300 text-lg flex items-center justify-center">
                    Продолжить
                </button>
                <button @click="openConsent = false" class="text-gray-400 text-sm hover:text-gray-600 font-medium py-2">Отмена</button>
            </div>
        </div>
    </div>
</div>