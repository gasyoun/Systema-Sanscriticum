<section class="py-10 lg:py-16 bg-white" id="order-form-anchor">
    <div class="container mx-auto px-4">
        
        <div class="max-w-2xl mx-auto">
            
            {{-- Описание перед формой (если есть) --}}
            @if(!empty($data['description']))
                <div class="text-center mb-10 prose prose-lg prose-slate mx-auto">
                    {!! $data['description'] !!}
                </div>
            @endif

            {{-- САМА ФОРМА (Центрированная) --}}
            {{-- 1. ИЗМЕНЕНИЕ: Ставим false по умолчанию --}}
            <div class="bg-[#19191C] rounded-[2rem] p-8 md:p-10 shadow-2xl"
                 x-data="{ agreedForm: false, agreedPromo: false }">
                
                <h3 class="text-2xl font-extrabold text-white mb-2 text-center">
                    {{ $data['title'] ?? 'Записаться на курс' }}
                </h3>
                <p class="text-[#86868B] text-sm mb-8 text-center">
                    Оставьте заявку, и мы свяжемся с вами в Telegram.
                </p>

                @if(session('success'))
                    <div class="p-4 mb-6 rounded-xl bg-green-900/30 border border-green-500/30 text-green-400 text-center font-bold text-sm">
                        {{ session('success') }}
                    </div>
                @endif

                <form action="{{ route('leads.store') }}" method="POST" class="space-y-5">
                    @csrf
                    
                    {{-- Определение ID лендинга --}}
                    @php
                        $landingId = '';
                        if (isset($page) && $page->id) {
                            $landingId = $page->id;
                        } elseif (request()->route('slug')) {
                            $landingId = \App\Models\LandingPage::where('slug', request()->route('slug'))->value('id');
                        }
                    @endphp

                    <input type="hidden" name="landing_page_id" value="{{ $landingId }}">
                    
                    {{-- Скрытые поля аналитики --}}
                    <input type="hidden" name="utm_source" class="analytics-field">
                    <input type="hidden" name="utm_medium" class="analytics-field">
                    <input type="hidden" name="utm_campaign" class="analytics-field">
                    <input type="hidden" name="utm_content" class="analytics-field">
                    <input type="hidden" name="utm_term" class="analytics-field">
                    <input type="hidden" name="click_id" class="analytics-field">
                    <input type="hidden" name="referrer" class="analytics-field" value="{{ request()->headers->get('referer') }}">

                    {{-- Поля ввода --}}
                    <div>
                        <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">Ваше имя</label>
                        <input type="text" name="name" required placeholder="Иван"
                               class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                    </div>
                    
                    <div>
                        <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">Телефон / Telegram</label>
                        <input type="text" name="contact" required placeholder="+7 999 000-00-00"
                               class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">Email</label>
                        <input type="email" name="email" required placeholder="mail@example.com"
                               class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                    </div>

                    {{-- Чекбоксы --}}
                    <div class="space-y-3 pt-2">
                        
                        {{-- Чекбокс 1: Обязательный (Персональные данные) --}}
                        <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                            <div class="flex items-center h-5 mt-0.5 shrink-0">
                                {{-- Исправлено: x-model теперь совпадает с переменной x-data (agreedForm) --}}
                                <input type="checkbox" x-model="agreedForm" class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                            </div>
                            <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                Я даю <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                            </div>
                        </label>

                        {{-- Чекбокс 2: Рассылка (Добавлен name) --}}
                        <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                            <div class="flex items-center h-5 mt-0.5 shrink-0">
                                {{-- Добавлен name и правильный x-model --}}
                                <input type="checkbox" 
                                       name="is_promo_agreed" 
                                       x-model="agreedPromo" 
                                       class="w-5 h-5 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                            </div>
                            <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                Я даю <span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на получение рассылки
                            </div>
                        </label>
                    </div>

                    {{-- КНОПКА ОТПРАВКИ --}}
                    {{-- Блокируется, если agreedForm == false --}}
                    <button type="submit" 
                            :disabled="!agreedForm"
                            :class="agreedForm ? 'bg-[#E85C24] hover:bg-[#d04a15] transform hover:-translate-y-0.5 shadow-lg shadow-orange-900/20 text-white cursor-pointer' : 'bg-gray-600 text-gray-400 cursor-not-allowed opacity-50'"
                            class="w-full font-extrabold py-4 rounded-xl transition-all duration-300 text-base uppercase tracking-wider mt-4">
                        {{ $data['button_text'] ?? 'ЗАПИСАТЬСЯ' }}
                    </button>

                </form>
            </div>

        </div>
    </div>
</section>