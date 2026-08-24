<section class="py-10 lg:py-16 bg-white" id="order-form-anchor">
    <div class="container mx-auto px-4">

        <div class="max-w-2xl mx-auto">

            {{-- Описание перед формой (если есть) --}}
            @if(!empty($data['description']))
                <div class="text-center mb-10 prose prose-lg prose-slate mx-auto">
                    {!! \App\Support\RichHtml::sanitize($data['description']) !!}
                </div>
            @endif

            @php
                // Тексты согласий переопределяются данными блока (pd_text / promo_text).
                $landingId = '';
                if (isset($page) && $page->id) {
                    $landingId = $page->id;
                } elseif (request()->route('slug')) {
                    $landingId = \App\Models\LandingPage::where('slug', request()->route('slug'))->value('id');
                }
            @endphp

            {{-- ВАРИАНТ A: вошедший ученик — ноль полей, заявка из профиля --}}
            @if(auth()->check())
                <div class="bg-[#19191C] rounded-[2rem] p-8 md:p-10 shadow-2xl"
                     x-data="{ agreedForm: false, agreedPromo: false }">

                    <h3 class="text-2xl font-extrabold text-white mb-2 text-center">
                        {{ $data['title'] ?? 'Записаться на курс' }}
                    </h3>
                    <p class="text-[#86868B] text-sm mb-2 text-center">
                        {{ $data['subtitle_auth'] ?? 'Заявка заполнится из вашего кабинета сама.' }}
                    </p>
                    <p class="text-white text-sm mb-8 text-center font-semibold">
                        Вы вошли как {{ auth()->user()->name }}
                    </p>

                    @if(session('success'))
                        <div class="p-4 mb-6 rounded-xl bg-green-900/30 border border-green-500/30 text-green-400 text-center font-bold text-sm">
                            {{ session('success') }}
                        </div>
                    @endif

                    {{-- H3339: заявившийся из кабинета тоже подключает уведомления. --}}
                    @if(session('status_connect_links'))
                        @include('promo.partials.status-connect', ['links' => session('status_connect_links')])
                    @endif

                    <form action="{{ route('leads.one-click') }}" method="POST" class="space-y-5">
                        @csrf

                        <input type="hidden" name="landing_page_id" value="{{ $landingId }}">

                        {{-- Скрытые поля аналитики --}}
                        <input type="hidden" name="utm_source" class="analytics-field">
                        <input type="hidden" name="utm_medium" class="analytics-field">
                        <input type="hidden" name="utm_campaign" class="analytics-field">
                        <input type="hidden" name="utm_content" class="analytics-field">
                        <input type="hidden" name="utm_term" class="analytics-field">
                        <input type="hidden" name="click_id" class="analytics-field">
                        <input type="hidden" name="referrer" class="analytics-field" value="{{ request()->headers->get('referer') }}">

                        {{-- Чекбоксы --}}
                        <div class="space-y-3 pt-2">
                            {{-- Чекбокс 1: Обязательный (Персональные данные) --}}
                            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                                <div class="flex items-center h-5 mt-0.5 shrink-0">
                                    <input type="checkbox" x-model="agreedForm" class="w-5 h-5 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer transition-colors">
                                </div>
                                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                    {{ $data['pd_text'] ?? 'Чтобы попасть в лист ожидания, я даю' }} <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                                </div>
                            </label>

                            {{-- Чекбокс 2: Анонсы (опциональный, по умолчанию снят) --}}
                            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                                <div class="flex items-center h-5 mt-0.5 shrink-0">
                                    <input type="checkbox"
                                           name="is_promo_agreed"
                                           x-model="agreedPromo"
                                           class="w-5 h-5 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer transition-colors">
                                </div>
                                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                    {{ $data['promo_text'] ?? 'Хочу получать анонсы школы — примерно раз в месяц: старты потоков, расписание, набор групп' }} (<span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">согласие на рассылку</span>)
                                </div>
                            </label>
                        </div>

                        {{-- КНОПКА ОТПРАВКИ --}}
                        <button type="submit"
                                :disabled="!agreedForm"
                                :class="agreedForm ? 'bg-brand hover:bg-brand-hover transform hover:-translate-y-0.5 shadow-lg shadow-orange-900/20 text-white cursor-pointer' : 'bg-gray-600 text-gray-400 cursor-not-allowed opacity-50'"
                                class="w-full font-extrabold py-4 rounded-xl transition-all duration-300 text-base uppercase tracking-wider mt-4">
                            {{ $data['button_text_auth'] ?? 'Встать в лист ожидания' }}
                        </button>

                    </form>
                </div>
            @else
                {{-- ВАРИАНТ B: гость — контакт обязателен, имя и почта нет --}}
                <div class="bg-[#19191C] rounded-[2rem] p-8 md:p-10 shadow-2xl"
                     x-data="{ agreedForm: false, agreedPromo: false }">

                    <h3 class="text-2xl font-extrabold text-white mb-2 text-center">
                        {{ $data['title'] ?? 'Записаться на курс' }}
                    </h3>
                    <p class="text-[#86868B] text-sm mb-8 text-center">
                        {{ $data['subtitle'] ?? 'Оставьте заявку, и мы свяжемся с вами в Telegram.' }}
                    </p>

                    @if(session('success'))
                        <div class="p-4 mb-6 rounded-xl bg-green-900/30 border border-green-500/30 text-green-400 text-center font-bold text-sm">
                            {{ session('success') }}
                        </div>
                    @endif

                    <form action="{{ route('leads.store') }}" method="POST" class="space-y-5">
                        @csrf

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
                            <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">
                                Ваше имя
                                <span class="text-[#505055] normal-case tracking-normal font-medium">— необязательно</span>
                            </label>
                            <input type="text" name="name" placeholder="Иван"
                                   class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">Телефон / Telegram</label>
                            <input type="text" name="contact" required placeholder="+7 999 000-00-00"
                                   class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">
                                Email
                                <span class="text-[#505055] normal-case tracking-normal font-medium">— необязательно</span>
                            </label>
                            <input type="email" name="email" placeholder="mail@example.com"
                                   class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-[#65656B] uppercase tracking-widest mb-2">
                                Telegram / VK / Instagram
                                <span class="text-[#505055] normal-case tracking-normal font-medium">— необязательно</span>
                            </label>
                            <input type="text" name="social" placeholder="@username или ссылка"
                                   maxlength="255"
                                   value="{{ old('social') }}"
                                   class="w-full px-5 py-4 rounded-xl border border-transparent bg-[#252529] text-white placeholder-[#505055] focus:bg-[#2C2C32] focus:border-[#3E3E45] focus:ring-0 transition text-base">
                        </div>

                        {{-- Чекбоксы --}}
                        <div class="space-y-3 pt-2">
                            {{-- Чекбокс 1: Обязательный (Персональные данные) --}}
                            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                                <div class="flex items-center h-5 mt-0.5 shrink-0">
                                    <input type="checkbox" x-model="agreedForm" class="w-5 h-5 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer transition-colors">
                                </div>
                                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                    {{ $data['pd_text'] ?? 'Чтобы попасть в лист ожидания, я даю' }} <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                                </div>
                            </label>

                            {{-- Чекбокс 2: Анонсы (опциональный, по умолчанию снят) --}}
                            <label class="flex items-start gap-3 text-left p-3 sm:p-4 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                                <div class="flex items-center h-5 mt-0.5 shrink-0">
                                    <input type="checkbox"
                                           name="is_promo_agreed"
                                           x-model="agreedPromo"
                                           class="w-5 h-5 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer transition-colors">
                                </div>
                                <div class="text-xs sm:text-sm text-gray-600 leading-relaxed select-none group-hover:text-gray-900 transition">
                                    {{ $data['promo_text'] ?? 'Хочу получать анонсы школы — примерно раз в месяц: старты потоков, расписание, набор групп' }} (<span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-brand hover:text-brand-hover hover:underline font-semibold cursor-pointer">согласие на рассылку</span>)
                                </div>
                            </label>
                        </div>

                        {{-- КНОПКА ОТПРАВКИ --}}
                        <button type="submit"
                                :disabled="!agreedForm"
                                :class="agreedForm ? 'bg-brand hover:bg-brand-hover transform hover:-translate-y-0.5 shadow-lg shadow-orange-900/20 text-white cursor-pointer' : 'bg-gray-600 text-gray-400 cursor-not-allowed opacity-50'"
                                class="w-full font-extrabold py-4 rounded-xl transition-all duration-300 text-base uppercase tracking-wider mt-4">
                            {{ $data['button_text'] ?? 'ЗАПИСАТЬСЯ' }}
                        </button>

                    </form>
                </div>
            @endif

        </div>
    </div>
</section>
