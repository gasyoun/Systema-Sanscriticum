{{-- H1975 — skin B, light island (O2). Default variant, not a sole winner
     (H1966 multi-dir policy — A/C/D ship as siblings). Tokens + wireframe
     from marketing/marathon-2026-08/redesign/direction-b-light.html and the
     packet's Direction B section; USEIT Must-fix set folded in inline
     (see marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md). --}}
@php
    /** @var array $copy H1067 — default A, switch B via MARATHON_LANDING_COPY_VARIANT (copy axis, independent of this skin) */
    $v = $copy['variant'] ?? [];
    $heroTitle = $v['hero_title'] ?? 'Пойми, как устроен санскрит, и выбери свой курс';
    $heroSubtitle = $v['hero_subtitle'] ?? '3 дня, ~15 минут в день, в своем темпе.';
    $cta = $v['cta'] ?? 'Записаться';
    $benefitsHeading = $v['benefits_heading'] ?? '';
    $benefits = $v['benefits'] ?? [];
    $days = $copy['days'] ?? [];
    $faq = $copy['faq'] ?? [];
    $testimonial = trim((string) ($copy['testimonial'] ?? ''));
@endphp
{{-- O2 full-width light canvas under the dark shop header — the shop body
     itself (layouts/shop.blade.php) stays bg-[#0A0D14]; this island is the
     one full-bleed break, no light tokens leak outside it (USEIT H4-1/H8-1). --}}
<div class="min-h-screen bg-stone-50 text-stone-900">
    <div class="max-w-2xl mx-auto px-4 py-10 md:py-14">

        {{-- H1067 anti-urgency hero — no countdown, no "spots left", evergreen entry any day. --}}
        <div class="text-center mb-8">
            <span class="inline-block text-[11px] font-extrabold uppercase tracking-wide text-[#E85C24] bg-orange-50 rounded-md px-2 py-1 mb-3">
                Бесплатная консультация
            </span>
            <h1 class="text-3xl md:text-4xl font-black text-stone-900 mb-4 leading-tight">
                {{ $heroTitle }}
            </h1>
            <p class="text-stone-600 text-lg font-semibold mb-2">
                {{ $heroSubtitle }}
            </p>
            <p class="text-sm text-stone-500">{{ $hostName }} · начать можно в любой день</p>
        </div>

        @if (session('marathon_result'))
            {{-- USEIT H1-2 — success pinned right under the hero, above fold, not lost below a scroll. --}}
            <div id="marathon-success"
                 x-data="{ tgClicked: false, copied: false }"
                 class="mb-8 p-6 bg-emerald-50 border border-emerald-200 rounded-2xl"
                 aria-live="polite">
                <h3 class="font-extrabold text-emerald-800 text-lg mb-2">Вы записаны</h3>
                <p class="text-emerald-800 text-sm leading-relaxed mb-4">
                    Чтобы получить личный День 1 и День 2, нажмите кнопку и запустите бота — без этого шага дни не придут.
                </p>

                @if (session('marathon_telegram_link'))
                    <a href="{{ session('marathon_telegram_link') }}" target="_blank" rel="noopener"
                       @click="tgClicked = true"
                       class="block text-center bg-[#0088cc] hover:bg-[#0077b3] text-white font-extrabold px-6 py-3 rounded-2xl transition-colors">
                        Продолжить в Telegram
                    </a>
                    <button type="button"
                            @click="navigator.clipboard.writeText(@js(session('marathon_telegram_link'))).then(() => copied = true)"
                            class="w-full mt-2 px-6 py-2.5 border border-stone-300 text-stone-600 font-bold rounded-2xl hover:bg-stone-100 transition-colors"
                            x-text="copied ? 'Ссылка скопирована' : 'Скопировать ссылку'">
                        Скопировать ссылку
                    </button>
                    {{-- USEIT H1-1/H9-2 — post-Telegram-click inline state, no silent click. --}}
                    <div x-show="tgClicked" x-cloak
                         class="mt-3 p-3 bg-sky-50 rounded-xl text-sm text-sky-900">
                        Открыли Telegram. Нажмите <b>Start</b> у бота — иначе дни не придут. Если окно не открылось — скопируйте ссылку выше или нажмите снова.
                    </div>
                @endif

                <ol class="mt-4 pl-5 text-sm text-emerald-800 list-decimal space-y-1">
                    <li>Start у бота</li>
                    <li>Дождитесь сообщения Дня 1</li>
                    <li>Канал новостей (по желанию):
                        <a href="{{ config('marathon.telegram_channel_url') }}" class="underline">{{ config('marathon.telegram_channel_url') }}</a>
                    </li>
                </ol>
            </div>

            @if (session('marathon_track') === 'paid' && ! session('marathon_paid'))
                <div class="mb-8 p-6 bg-orange-50 border border-orange-200 rounded-2xl">
                    <p class="font-extrabold text-stone-900 mb-1">Шаг 2 из 2 — оплата трека «с проверкой»</p>
                    <p class="text-sm text-stone-600 mb-4">
                        Оплатите {{ $paidTrackPrice }} ₽ — куратор будет проверять вашу практику Дней 1–2,
                        и вам гарантировано место на живой консультации Дня 3.
                    </p>
                    <form method="POST" action="{{ route('marathon.pay') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="contact" value="{{ session('marathon_contact') }}">
                        <div>
                            <label class="block text-sm font-bold text-stone-800 mb-1" for="marathon-pay-email">Email для чека</label>
                            <input id="marathon-pay-email" type="email" name="email" required
                                   class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                        </div>
                        @error('email')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                            Оплатить {{ $paidTrackPrice }} ₽
                        </button>
                    </form>
                </div>
            @endif
        @endif

        @if (session('error'))
            <div class="mb-8 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @if (!empty($days))
            {{-- USEIT H6-1 — day cards, not a bare <ol>. --}}
            <section class="mb-10" aria-label="Три дня">
                <h2 class="text-lg font-extrabold text-stone-900 mb-3">Как устроены три дня</h2>
                <div class="grid gap-3">
                    @foreach ($days as $i => $day)
                        <div class="bg-white border border-stone-200 rounded-2xl p-4 grid grid-cols-[2.25rem_1fr] gap-3 items-start">
                            <div class="w-9 h-9 rounded-full bg-orange-50 text-[#E85C24] font-extrabold flex items-center justify-center shrink-0">
                                {{ $i + 1 }}
                            </div>
                            <div>
                                <p class="font-extrabold text-stone-900 mb-0.5">{{ $day['title'] }}</p>
                                <p class="text-sm text-stone-600 leading-relaxed">{{ $day['body'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (!empty($benefits))
            <section class="mb-10">
                @if ($benefitsHeading !== '')
                    <h2 class="text-lg font-extrabold text-stone-900 mb-3">{{ $benefitsHeading }}</h2>
                @endif
                <div class="divide-y divide-stone-200">
                    @foreach ($benefits as $benefit)
                        <div class="py-3.5">
                            <p class="font-extrabold text-stone-900 mb-0.5">{{ $benefit['title'] }}</p>
                            <p class="text-sm text-stone-600 leading-relaxed">{{ $benefit['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($testimonial !== '')
            <blockquote class="mb-10 p-6 bg-orange-50 border-l-4 border-[#E85C24] rounded-r-2xl text-stone-700 italic">
                {{ $testimonial }}
            </blockquote>
        @endif

        <form method="POST" action="{{ route('marathon.register') }}"
              x-data="{ track: '{{ old('track', 'free') }}' }"
              class="bg-white rounded-[20px] shadow-sm border border-stone-200 p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <label for="marathon-quiz" class="block text-sm font-extrabold text-stone-800 mb-2">Что вас привлекает в санскрите?</label>
                <select id="marathon-quiz" name="quiz_goal" required
                        class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                    <option value="">Выберите…</option>
                    @foreach ($quizGoals as $key => $label)
                        <option value="{{ $key }}" @selected(old('quiz_goal') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('quiz_goal')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <fieldset class="border-0 p-0 m-0">
                <legend class="block text-sm font-extrabold text-stone-800 mb-2">Формат участия</legend>
                <div class="grid gap-2" @error('track') aria-describedby="track-error" @enderror>
                    <label class="flex items-start gap-3 p-4 rounded-2xl border-[1.5px] cursor-pointer transition-colors"
                           :class="track === 'free' ? 'border-[#E85C24] bg-orange-50' : 'border-stone-200'">
                        <input type="radio" name="track" value="free" x-model="track" class="mt-1">
                        <span>
                            <span class="font-extrabold block text-stone-900">Бесплатно</span>
                            <span class="text-sm text-stone-600">2 дня записей + маршрут + консультация в записи.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 p-4 rounded-2xl border-[1.5px] cursor-pointer transition-colors"
                           :class="track === 'paid' ? 'border-[#E85C24] bg-orange-50' : 'border-stone-200'">
                        <input type="radio" name="track" value="paid" x-model="track" class="mt-1">
                        <span>
                            <span class="font-extrabold block text-stone-900">«С проверкой» — {{ $paidTrackPrice }} ₽</span>
                            <span class="text-sm text-stone-600">
                                Куратор смотрит вашу практику лично, разбор на живой консультации с {{ $hostName }},
                                скидка {{ $couponAmount }} ₽ на любой первый курс.
                            </span>
                        </span>
                    </label>
                </div>
                @error('track')
                    <p id="track-error" class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </fieldset>

            <div>
                <label for="marathon-name" class="block text-sm font-extrabold text-stone-800 mb-2">Имя <span class="text-red-500">*</span></label>
                <input id="marathon-name" type="text" name="name" required minlength="2" maxlength="255"
                       value="{{ old('name') }}"
                       class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                @error('name')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="marathon-contact" class="block text-sm font-extrabold text-stone-800 mb-2">Контакт <span class="text-red-500">*</span></label>
                {{-- USEIT H5-1 — placeholder examples instead of a bare required field. --}}
                <input id="marathon-contact" type="text" name="contact" required value="{{ old('contact') }}"
                       placeholder="+7… / name@mail.ru / @username"
                       class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                @error('contact')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full px-6 py-3.5 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                {{ $cta }}
            </button>
            <p class="text-xs text-stone-500 text-center -mt-3">Без дедлайнов и «осталось N мест». Начать можно в любой день.</p>
        </form>

        @if (!empty($faq))
            <section class="mt-12 space-y-2.5" x-data="{ open: null }">
                <h2 class="text-lg font-extrabold text-stone-900 mb-3">Частые вопросы</h2>
                @foreach ($faq as $i => $item)
                    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
                        <button type="button"
                                class="w-full text-left p-4 font-extrabold text-stone-900 flex justify-between gap-3"
                                :aria-expanded="(open === {{ $i }}).toString()"
                                aria-controls="faq-panel-{{ $i }}"
                                @click="open === {{ $i }} ? open = null : open = {{ $i }}">
                            <span>{{ $item['q'] }}</span>
                            <span class="text-[#E85C24]" aria-hidden="true" x-text="open === {{ $i }} ? '−' : '+'"></span>
                        </button>
                        <div id="faq-panel-{{ $i }}" class="px-4 pb-4 text-sm text-stone-600 leading-relaxed" x-show="open === {{ $i }}" x-cloak>
                            {{ $item['a'] }}
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

    </div>
</div>
