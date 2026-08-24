{{-- Оплата по частям (H1290): тихая точка входа под кнопкой оплаты. Запрос
     только зовёт куратора (CheckoutController::requestInstallments) — ни
     PaymentPromise, ни рассрочка отсюда не создаются (ruling D6). Требует
     переменную $tariff. Показывается, только если настроен кураторский чат
     (services.telegram.curators_chat_id) — иначе запрос ушёл бы в пустоту,
     а студенту было бы обещано «куратор свяжется». --}}
@if((string) (config('services.telegram.curators_chat_id') ?? '') !== '')
@php($installmentsErrors = $errors->getBag('installments'))
<div id="installments">
    @if(session('installments_requested'))
        <div class="bg-green-50 border border-green-200 rounded-2xl p-5 text-sm text-green-900">
            <p class="font-bold mb-1.5"><i class="fas fa-check-circle mr-1.5"></i>Запрос отправлен</p>
            <p class="leading-relaxed">
                Запрос у куратора — обычно отвечаем в течение рабочего дня.
                Платить сейчас ничего не нужно: сначала согласуете график.
            </p>
            <p class="mt-2 leading-relaxed">
                Если удобнее, напишите нам напрямую:
                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener"
                   class="font-bold underline underline-offset-2 hover:text-green-700">Написать в Telegram</a>.
            </p>
        </div>
    @else
        <div x-data="{ open: @json($installmentsErrors->any()) }"
             class="bg-white border border-gray-200 rounded-2xl p-4 sm:p-5">
            <button type="button" @click="open = !open"
                    class="w-full flex items-center justify-between gap-3 text-left group">
                <span class="text-sm font-medium text-gray-600 group-hover:text-gray-800 transition">
                    Нужно разбить оплату на части?
                </span>
                <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform"
                   :class="open && 'rotate-180'"></i>
            </button>

            <div x-show="open" x-transition.opacity style="display: none;" class="mt-4 space-y-4">
                <p class="text-sm text-gray-600 leading-relaxed">
                    Напишите куратору — вместе согласуете график, который вам подходит.
                    Это договоренность напрямую со школой: без банка и кредитных анкет.
                    Запрос ни к чему не обязывает, платить сейчас ничего не нужно.
                </p>

                <form action="{{ route('checkout.installments', $tariff) }}" method="POST" class="space-y-3">
                    @csrf

                    @guest
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Как к вам обращаться <span class="text-gray-400 font-normal">(необязательно)</span>
                            </label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="Например, Иван"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5 px-4 text-sm transition">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Как с вами связаться <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="contact" required value="{{ old('contact') }}"
                                   placeholder="Telegram, телефон или email"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5 px-4 text-sm transition">
                            @if($installmentsErrors->has('contact'))
                                <p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $installmentsErrors->first('contact') }}</p>
                            @endif
                        </div>
                    @endguest

                    @auth
                        <p class="text-xs text-gray-400">Куратор напишет вам по контактам из профиля.</p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Куда удобнее написать <span class="text-gray-400 font-normal">(необязательно)</span>
                            </label>
                            <input type="text" name="contact" value="{{ old('contact') }}"
                                   placeholder="Telegram, телефон или email"
                                   class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5 px-4 text-sm transition">
                            @if($installmentsErrors->has('contact'))
                                <p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $installmentsErrors->first('contact') }}</p>
                            @endif
                        </div>
                    @endauth

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Комментарий <span class="text-gray-400 font-normal">(необязательно)</span>
                        </label>
                        <textarea name="comment" rows="2"
                                  placeholder="Если хотите — пара слов о том, какой график был бы удобен"
                                  class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2.5 px-4 text-sm transition">{{ old('comment') }}</textarea>
                        @if($installmentsErrors->has('comment'))
                            <p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $installmentsErrors->first('comment') }}</p>
                        @endif
                    </div>

                    <button type="submit"
                            class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl border border-indigo-200 text-indigo-700 font-bold text-sm hover:bg-indigo-50 transition">
                        Отправить запрос куратору
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
@endif
