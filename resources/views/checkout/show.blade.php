@extends('layouts.shop')
@section('title', 'Оформление заказа')

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('checkout', {
        prana: 0,
        maxPrana: @json((int) ($pranaMaxSpend ?? 0)),
        rate: @json((int) ($pranaRate ?? 10)),
        basePrice: @json((int) round((float) $finalPrice)),
        listPrice: @json((int) round((float) ($basePrice ?? $tariff->price))),
        loyaltyDiscount: @json((int) round((float) ($loyaltyDiscount ?? 0))),
        promoDiscount: @json((int) round((float) ($discountAmount ?? 0))),
        appliedPromo: @json($appliedPromo ? ['code' => $appliedPromo->code] : null),
        pranaSharePct: @json((int) ($pranaSharePct ?? 30)),
        promoBusy: false,
        promoError: '',
        promoFlash: '',

        get pranaRubles() {
            if (!this.rate) return 0;
            return Math.floor((this.prana || 0) / this.rate);
        },
        get total() {
            return Math.max(1, this.basePrice - this.pranaRubles);
        },
        get totalSavings() {
            return this.loyaltyDiscount + this.promoDiscount + this.pranaRubles;
        },
        format(n) {
            return Number(n || 0).toLocaleString('ru-RU');
        },

        applyState(s) {
            this.basePrice       = s.finalPrice;
            this.maxPrana        = s.pranaMaxSpend;
            this.rate            = s.pranaRate;
            this.pranaSharePct   = s.pranaSharePct;
            this.promoDiscount   = s.discountAmount;
            this.loyaltyDiscount = Math.max(0, this.listPrice - s.finalPrice - s.discountAmount);
            this.appliedPromo    = s.appliedPromo;
            if (this.prana > this.maxPrana) this.prana = this.maxPrana;
        },

        async applyPromo(code) {
            if (!code || this.promoBusy) return;
            this.promoBusy = true; this.promoError = ''; this.promoFlash = '';
            try {
                const r = await fetch(@json(route('checkout.promo', $tariff->id)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ code }),
                });
                const data = await r.json();
                if (!r.ok || !data.ok) {
                    this.promoError = data.message || 'Не удалось применить промокод.';
                    return;
                }
                this.applyState(data.state);
                this.promoFlash = data.message || 'Промокод применён.';
            } catch (e) {
                this.promoError = 'Сетевая ошибка. Попробуйте ещё раз.';
            } finally {
                this.promoBusy = false;
            }
        },

        async removePromo() {
            if (this.promoBusy) return;
            this.promoBusy = true; this.promoError = ''; this.promoFlash = '';
            try {
                const r = await fetch(@json(route('checkout.promo.remove', $tariff->id)), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                });
                const data = await r.json();
                if (data.ok) {
                    this.applyState(data.state);
                    this.promoFlash = data.message || 'Промокод снят.';
                }
            } catch (e) {
                this.promoError = 'Сетевая ошибка.';
            } finally {
                this.promoBusy = false;
            }
        },
    });
});

// ── Анти-419: держим CSRF-токен свежим ──────────────────────────────────
// Сессия-файл могла истечь/собраться GC, пока студент думал над оплатой;
// на сабмите его пере-аутентифицирует remember-me кука в НОВУЮ сессию с НОВЫМ
// токеном, а форма всё ещё несёт старый _token → 419. Поэтому перед реальной
// отправкой подтягиваем токен текущей живой сессии. Плюс обновляем токен при
// возврате страницы из bfcache («Назад»).
(function () {
    const meta = () => document.querySelector('meta[name=csrf-token]');

    function applyToken(token) {
        if (!token) return;
        const m = meta();
        if (m) m.content = token;
        document.querySelectorAll('input[name=_token]').forEach((i) => { i.value = token; });
    }

    async function fetchToken() {
        const r = await fetch(@json(route('csrf.token')), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await r.json();
        return data.token;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('checkout-form');
        if (!form) return;
        const btn = document.querySelector('button[form="checkout-form"]');
        let refreshed = false;

        form.addEventListener('submit', async function (e) {
            if (refreshed) return; // второй проход — отправляем по-настоящему
            e.preventDefault();
            if (btn) { btn.disabled = true; btn.classList.add('opacity-70', 'cursor-wait'); }
            try {
                applyToken(await fetchToken());
            } catch (_) {
                // сеть недоступна — токен мог и не протухнуть, всё равно пробуем отправить
            }
            refreshed = true;
            form.submit();
        });
    });

    // Возврат из bfcache: страница со старым токеном — обновим
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        fetchToken().then(applyToken).catch(() => {});
    });
})();
</script>
<style>
    .checkout-slider {
        -webkit-appearance: none; appearance: none;
        width: 100%; height: 8px; border-radius: 9999px;
        background: linear-gradient(to right,
            #E85C24 0%,
            #E85C24 var(--fill, 0%),
            #FED7AA var(--fill, 0%),
            #FFEDD5 100%);
        outline: none; cursor: pointer;
        transition: box-shadow .2s;
    }
    .checkout-slider:focus-visible { box-shadow: 0 0 0 4px rgba(232,92,36,.25); }
    .checkout-slider::-webkit-slider-thumb {
        -webkit-appearance: none; appearance: none;
        width: 22px; height: 22px; border-radius: 9999px;
        background: #fff; border: 3px solid #E85C24;
        box-shadow: 0 4px 10px rgba(232,92,36,.35);
        cursor: grab; transition: transform .15s;
    }
    .checkout-slider::-webkit-slider-thumb:hover { transform: scale(1.12); }
    .checkout-slider::-moz-range-thumb {
        width: 22px; height: 22px; border-radius: 9999px;
        background: #fff; border: 3px solid #E85C24;
        box-shadow: 0 4px 10px rgba(232,92,36,.35);
        cursor: grab;
    }
    @keyframes checkoutPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.04); } }
    .checkout-pulse { animation: checkoutPulse 1.5s ease-in-out infinite; }
</style>
@endpush

@section('content')
<div class="min-h-screen bg-gradient-to-b from-gray-50 to-white py-10 md:py-16 font-sans antialiased">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="mb-10">
            <a href="{{ url()->previous() }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 transition mb-4">
                <i class="fas fa-arrow-left mr-2 text-xs"></i> Назад
            </a>
            <h1 class="text-3xl md:text-4xl font-extrabold text-gray-950 tracking-tight">Оформление заказа</h1>
            <p class="mt-2 text-base text-gray-500">Проверьте детали и подтвердите оплату — доступ откроется сразу после.</p>
        </div>

        @guest
            <div class="mb-10">
                @include('partials.guest-purchase-warning', ['variant' => 'light'])
            </div>
        @endguest

        @if($finalPrice == 0 && auth()->check())
            <div class="bg-white p-8 rounded-3xl shadow-xl shadow-gray-100/30 border border-gray-100 text-center flex flex-col items-center">
                <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mb-6 border border-green-100">
                    <i class="fas fa-check text-4xl text-green-500"></i>
                </div>
                <h2 class="text-3xl font-extrabold text-gray-900 mb-3 tracking-tight">У вас уже есть доступ!</h2>
                <p class="text-gray-500 text-base mb-8 max-w-md">Вы полностью оплатили этот тариф ранее. Можете сразу приступать к занятиям в личном кабинете.</p>
                <a href="{{ route('student.dashboard') }}" class="inline-flex justify-center items-center px-10 py-4 text-lg font-bold rounded-2xl text-white bg-indigo-600 hover:bg-indigo-700 transition-all duration-200 shadow-lg shadow-indigo-200">
                    Перейти в личный кабинет
                </a>
            </div>
        @else
            @if(session('error'))
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm font-medium flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            {{-- Ошибки валидации (обяз. поля, анти-takeover email, гонки промо/праны).
                 Промо/прана не привязаны к видимым полям — нужен сводный catch-all. --}}
            @if($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm font-medium flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <ul class="space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Оплата из-за рубежа (PayPal) — заявка со сверкой вручную. --}}
            @include('partials.paypal-cta', ['tariff' => $tariff])

            <div x-data class="grid grid-cols-1 md:grid-cols-12 md:gap-x-10 lg:gap-x-14">

                {{-- ════════════════ ЛЕВАЯ КОЛОНКА ════════════════ --}}
                <div class="md:col-span-7 space-y-6 mb-10 md:mb-0">

                    <form action="{{ route('payment.create') }}" method="POST" id="checkout-form" class="space-y-6">
                        @csrf
                        <input type="hidden" name="tariff_id" value="{{ $tariff->id }}">
                        <input type="hidden" name="prana_amount" :value="$store.checkout.prana">

                        @guest
                            {{-- ─── Данные гостя ─── --}}
                            <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
                                <div class="flex items-center gap-3 mb-5">
                                    <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-sm font-extrabold">1</span>
                                    <h4 class="text-base font-extrabold text-gray-900">Ваши данные для доступа</h4>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-5">
                                    <div class="sm:col-span-1">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Фамилия <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="surname" required minlength="2" placeholder="Например, Иванов"
                                               value="{{ old('surname') }}"
                                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                                        @error('surname')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                                    </div>
                                    <div class="sm:col-span-1">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Имя <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="name" required minlength="2" placeholder="Например, Иван"
                                               value="{{ old('name') }}"
                                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                                        @error('name')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Email <span class="text-red-500">*</span>
                                            <small class="text-gray-500 ml-1">(сюда придёт доступ)</small>
                                        </label>
                                        <input type="email" name="email" required placeholder="ivan@example.com"
                                               pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$"
                                               value="{{ old('email') }}"
                                               class="peer block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                                        <p class="mt-1.5 text-xs text-red-500 hidden peer-[&:not(:placeholder-shown):invalid]:block">
                                            <i class="fas fa-exclamation-circle mr-1"></i> Укажите корректный email
                                        </p>
                                        @error('email')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Город <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="city" required placeholder="Например, Москва"
                                               value="{{ old('city') }}"
                                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                                        @error('city')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Год рождения <span class="text-gray-400 font-normal">(необязательно)</span>
                                        </label>
                                        <input type="number" name="birth_year" min="1900" max="{{ now()->format('Y') }}"
                                               placeholder="Например, 1990"
                                               value="{{ old('birth_year') }}"
                                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:invalid:border-red-500 focus:invalid:ring-red-500 py-3 px-4 transition">
                                        @error('birth_year')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input type="checkbox" name="wants_announcements" value="1" {{ old('wants_announcements', true) ? 'checked' : '' }}
                                                   class="mt-0.5 h-5 w-5 rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition">
                                            <span class="text-sm text-gray-600">Получать анонсы, новости и расписание на email</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        @endguest

                        {{-- ─── Слайдер праны ─── --}}
                        @auth
                            @if(\App\Services\Prana\PranaSettings::isActive() && ($pranaBalance ?? 0) > 0)
                                <div class="relative bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100 overflow-hidden">
                                    <div class="absolute -top-12 -right-12 w-40 h-40 bg-orange-100/40 rounded-full blur-3xl pointer-events-none"></div>
                                    <div class="relative flex items-start gap-4 mb-5">
                                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-orange-100 to-amber-50 text-[#E85C24] flex items-center justify-center shrink-0 text-2xl shadow-sm">
                                            <span aria-hidden="true">🪷</span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <h4 class="text-base font-extrabold text-gray-900 leading-tight">Списать прану</h4>
                                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-orange-50 text-[#E85C24] border border-orange-100">Скидка</span>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                                                Доступно <span class="font-bold text-gray-700">{{ number_format($pranaBalance, 0, '.', ' ') }}</span> праны.
                                                Можно покрыть до <span x-text="$store.checkout.pranaSharePct">{{ $pranaSharePct }}</span>% стоимости
                                                (<span x-text="$store.checkout.rate">{{ $pranaRate }}</span> праны = 1 ₽).
                                            </p>
                                        </div>
                                    </div>

                                    <template x-if="$store.checkout.maxPrana > 0">
                                        <div class="relative space-y-4">
                                            <div class="flex items-end justify-between">
                                                <div>
                                                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400">К списанию</div>
                                                    <div class="flex items-baseline gap-2 mt-0.5">
                                                        <span class="text-3xl font-black text-gray-900 tabular-nums" x-text="$store.checkout.format($store.checkout.prana)"></span>
                                                        <span class="text-sm font-medium text-gray-500">праны</span>
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400">Скидка</div>
                                                    <div class="text-2xl font-black text-[#E85C24] tabular-nums">
                                                        −<span x-text="$store.checkout.format($store.checkout.pranaRubles)"></span> ₽
                                                    </div>
                                                </div>
                                            </div>

                                            <input type="range" min="0"
                                                   :max="$store.checkout.maxPrana"
                                                   :step="$store.checkout.rate"
                                                   x-model.number="$store.checkout.prana"
                                                   :style="`--fill: ${$store.checkout.maxPrana ? ($store.checkout.prana / $store.checkout.maxPrana * 100) : 0}%`"
                                                   class="checkout-slider">

                                            <div class="flex items-center justify-between gap-3">
                                                <button type="button" @click="$store.checkout.prana = 0"
                                                        class="text-xs font-semibold text-gray-500 hover:text-gray-700 transition px-3 py-1.5 rounded-lg hover:bg-gray-100">
                                                    Сбросить
                                                </button>
                                                <button type="button" @click="$store.checkout.prana = $store.checkout.maxPrana"
                                                        class="text-xs font-bold text-[#E85C24] hover:text-white hover:bg-[#E85C24] border border-orange-200 hover:border-[#E85C24] rounded-lg px-3 py-1.5 transition-all">
                                                    Списать максимум — <span x-text="$store.checkout.format($store.checkout.maxPrana)"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="$store.checkout.maxPrana <= 0">
                                        <p class="text-xs text-gray-400 italic">
                                            Прану нельзя списать на этом заказе (минимальная сумма к оплате — 1 ₽).
                                        </p>
                                    </template>
                                </div>
                            @endif
                        @endauth
                    </form>

                    {{-- ─── Промокод (AJAX) ─── --}}
                    <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
                        <template x-if="$store.checkout.appliedPromo">
                            <div class="flex items-center justify-between bg-green-50 p-4 rounded-2xl border border-green-200">
                                <div class="flex items-center gap-3 min-w-0">
                                    <i class="fas fa-check-circle text-green-500 text-lg shrink-0"></i>
                                    <div class="min-w-0">
                                        <div class="text-sm font-bold text-green-800 truncate">
                                            Промокод <span class="font-mono" x-text="$store.checkout.appliedPromo?.code"></span> применён
                                        </div>
                                        <div class="text-xs text-green-700/80" x-show="$store.checkout.promoDiscount > 0">
                                            Скидка −<span x-text="$store.checkout.format($store.checkout.promoDiscount)"></span> ₽
                                        </div>
                                    </div>
                                </div>
                                <button type="button"
                                        @click="$store.checkout.removePromo()"
                                        :disabled="$store.checkout.promoBusy"
                                        class="text-sm font-medium text-gray-500 hover:text-red-500 transition px-2 py-1 disabled:opacity-50">
                                    Отменить
                                </button>
                            </div>
                        </template>

                        <template x-if="!$store.checkout.appliedPromo">
                            <div x-data="{ code: '' }">
                                <form @submit.prevent="$store.checkout.applyPromo(code).then(() => { if (!$store.checkout.promoError) code = ''; })">
                                    <div class="relative flex items-center">
                                        <i class="fas fa-ticket-alt absolute left-4 text-gray-400"></i>
                                        <input type="text" x-model="code" placeholder="Промокод (если есть)"
                                               :disabled="$store.checkout.promoBusy"
                                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm uppercase pl-11 pr-32 py-3.5 transition bg-gray-50 focus:bg-white disabled:opacity-60">
                                        <button type="submit"
                                                :disabled="!code.trim() || $store.checkout.promoBusy"
                                                class="absolute right-1.5 top-1.5 bottom-1.5 inline-flex justify-center items-center px-4 border border-transparent text-sm font-bold rounded-lg text-indigo-700 bg-indigo-100 hover:bg-indigo-200 disabled:opacity-50 disabled:cursor-not-allowed transition">
                                            <span x-show="!$store.checkout.promoBusy">Применить</span>
                                            <span x-show="$store.checkout.promoBusy"><i class="fas fa-spinner fa-spin"></i></span>
                                        </button>
                                    </div>
                                </form>
                                <p class="text-xs text-red-500 mt-2" x-show="$store.checkout.promoError" x-text="$store.checkout.promoError"></p>
                            </div>
                        </template>
                    </div>

                    {{-- ─── Кнопка оплаты ─── --}}
                    <div class="bg-white p-6 sm:p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
                        <button type="submit" form="checkout-form"
                                class="w-full flex justify-center items-center py-4 px-6 rounded-2xl shadow-lg shadow-orange-200/70 text-lg sm:text-xl font-extrabold text-white bg-gradient-to-r from-[#E85C24] to-[#d64e1c] hover:shadow-xl hover:shadow-orange-300/60 hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-[#E85C24]/30">
                            <i class="fas fa-lock mr-3 opacity-90 text-base"></i>
                            <span>К безопасной оплате</span>
                            <span class="mx-2 opacity-60">·</span>
                            <span class="tabular-nums" x-text="$store.checkout.format($store.checkout.total)">{{ number_format($finalPrice, 0, '.', ' ') }}</span>
                            <span class="ml-1.5">₽</span>
                        </button>

                        <p class="text-center text-xs text-gray-400 mt-4 leading-relaxed">
                            Нажимая на кнопку, вы соглашаетесь с
                            <a href="/docs/oferta.pdf" target="_blank" class="underline hover:text-gray-600">офертой</a> и
                            <a href="/docs/privacy.pdf" target="_blank" class="underline hover:text-gray-600">политикой конфиденциальности</a>.
                        </p>
                    </div>

                    <div class="hidden sm:flex items-center justify-center gap-6 text-xs text-gray-400 font-medium pt-2">
                        <span class="flex items-center gap-1.5"><i class="fas fa-shield-halved text-gray-300"></i> SSL-шифрование</span>
                        <span class="flex items-center gap-1.5"><i class="fas fa-credit-card text-gray-300"></i> Visa · MasterCard · МИР</span>
                        <span class="flex items-center gap-1.5"><i class="fas fa-rotate text-gray-300"></i> Возврат по оферте</span>
                    </div>
                </div>

                {{-- ════════════════ ПРАВАЯ КОЛОНКА ════════════════ --}}
                <div class="md:col-span-5 relative">
                    <div class="md:sticky md:top-6 space-y-6">

                        {{-- О тарифе --}}
                        <div class="bg-white p-7 rounded-3xl shadow-sm shadow-gray-100/60 border border-gray-100">
                            <span class="inline-flex w-max items-center px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider bg-indigo-100 text-indigo-800 mb-4">
                                {{ $tariff->course->title ?? 'Курс' }}
                            </span>

                            <h2 class="text-2xl md:text-[1.75rem] font-extrabold text-gray-900 mb-3 tracking-tight leading-tight">
                                {{ $tariff->title }}
                            </h2>

                            @if($tariff->description)
                                <p class="text-gray-600 text-sm leading-relaxed mb-5">
                                    {{ $tariff->description }}
                                </p>
                            @endif

                            <ul class="space-y-3 text-sm text-gray-700 font-medium border-t border-gray-100 pt-5">
                                <li class="flex items-center">
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                        <i class="fas fa-check text-green-600 text-[10px]"></i>
                                    </div>
                                    <span>Доступ к материалам сразу после оплаты</span>
                                </li>
                                <li class="flex items-center">
                                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-green-100 flex items-center justify-center mr-3">
                                        <i class="fas fa-check text-green-600 text-[10px]"></i>
                                    </div>
                                    <span>Обучение в личном кабинете студента</span>
                                </li>
                            </ul>
                        </div>

                        {{-- Итого --}}
                        <div class="relative overflow-hidden rounded-3xl text-white shadow-2xl shadow-indigo-200/40">
                            <div class="absolute inset-0 bg-gradient-to-br from-indigo-950 via-indigo-900 to-[#1a1454]"></div>
                            <div class="absolute -top-20 -right-20 w-64 h-64 bg-indigo-400/10 rounded-full blur-3xl"></div>
                            <div class="absolute -bottom-16 -left-16 w-48 h-48 bg-[#E85C24]/15 rounded-full blur-3xl"></div>

                            <div class="relative p-7 sm:p-8">

                                @if(!empty($isPersonal))
                                    <div class="mb-6 bg-white/5 border border-white/10 p-4 rounded-2xl flex items-center gap-4 backdrop-blur-sm">
                                        <div class="bg-gradient-to-br from-[#38BDF8] to-[#2da4dd] text-white rounded-full w-10 h-10 flex items-center justify-center flex-shrink-0 shadow-lg shadow-sky-900/30">
                                            <i class="fas fa-user-tag text-sm"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-extrabold text-white">Персональная скидка</p>
                                            <p class="text-xs text-indigo-200 mt-0.5 leading-relaxed">Применена к вашему аккаунту</p>
                                        </div>
                                    </div>
                                @elseif(!empty($isLoyal) && $isLoyal && empty($appliedPromo))
                                    <div class="mb-6 bg-white/5 border border-white/10 p-4 rounded-2xl flex items-center gap-4 backdrop-blur-sm">
                                        <div class="bg-gradient-to-br from-[#E85C24] to-[#d64e1c] text-white rounded-full w-10 h-10 flex items-center justify-center flex-shrink-0 shadow-lg shadow-orange-900/30">
                                            <i class="fas fa-crown text-sm"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-extrabold text-white">Скидка для своих: −{{ $tariff->getDiscountPercentForUser(auth()->user()) }}%</p>
                                            <p class="text-xs text-indigo-200 mt-0.5 leading-relaxed">Применена автоматически</p>
                                        </div>
                                    </div>
                                @endif

                                {{-- Разбивка --}}
                                <div class="space-y-2.5 mb-5 text-sm">
                                    <div class="flex justify-between items-center text-indigo-200">
                                        <span>Стоимость тарифа</span>
                                        <span class="tabular-nums" x-text="$store.checkout.format($store.checkout.listPrice) + ' ₽'">{{ number_format($basePrice ?? $tariff->price, 0, '.', ' ') }} ₽</span>
                                    </div>

                                    <div class="flex justify-between items-center text-amber-200" x-show="$store.checkout.loyaltyDiscount > 0">
                                        <span class="flex items-center gap-1.5">
                                            @if(!empty($isPersonal))
                                                <i class="fas fa-user-tag text-[10px]"></i> Персональная скидка
                                            @else
                                                <i class="fas fa-crown text-[10px]"></i> Скидка для своих
                                            @endif
                                        </span>
                                        <span class="tabular-nums">−<span x-text="$store.checkout.format($store.checkout.loyaltyDiscount)"></span> ₽</span>
                                    </div>

                                    <div class="flex justify-between items-center text-emerald-300" x-show="$store.checkout.promoDiscount > 0">
                                        <span class="flex items-center gap-1.5"><i class="fas fa-ticket text-[10px]"></i> Промокод</span>
                                        <span class="tabular-nums">−<span x-text="$store.checkout.format($store.checkout.promoDiscount)"></span> ₽</span>
                                    </div>

                                    <div class="flex justify-between items-center text-orange-300" x-show="$store.checkout.pranaRubles > 0">
                                        <span class="flex items-center gap-1.5"><span aria-hidden="true">🪷</span> Прана</span>
                                        <span class="tabular-nums">−<span x-text="$store.checkout.format($store.checkout.pranaRubles)"></span> ₽</span>
                                    </div>
                                </div>

                                <div class="border-t border-white/10 pt-5">
                                    <p class="text-[11px] font-bold text-indigo-300 uppercase tracking-widest mb-2">Итого к оплате</p>
                                    <div class="flex items-baseline gap-3 flex-wrap">
                                        <span class="text-5xl lg:text-[3.5rem] font-black tracking-tight text-white leading-none tabular-nums" x-text="$store.checkout.format($store.checkout.total)">
                                            {{ number_format($finalPrice, 0, '.', ' ') }}
                                        </span>
                                        <span class="text-2xl text-indigo-300 font-medium">₽</span>
                                    </div>

                                    <p class="text-xs text-emerald-300 font-bold mt-3 flex items-center gap-1.5"
                                       x-show="$store.checkout.totalSavings > 0">
                                        <i class="fas fa-piggy-bank"></i>
                                        Вы экономите
                                        <span class="tabular-nums">
                                            <span x-text="$store.checkout.format($store.checkout.totalSavings)"></span> ₽
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        @endif
    </div>
</div>
@endsection
