{{-- H1976 — skin A, dark-native (O1). Concurrent sibling with B/C/D
     (H1966 multi-dir policy), not a sole winner. Tokens + wireframe from
     marketing/marathon-2026-08/redesign/direction-a-dark.html and the
     packet's Direction A section; USEIT Must-fix set folded in inline
     (see marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md).
     The dark shop shell (layouts/shop.blade.php) is already bg-[#0A0D14]
     text-slate-200 — this skin stays IN that canvas (no light island,
     unlike skin B's O2 break), so surfaces sit one step lighter
     (--surface #111622) with a visible border (--border #1F2636). --}}
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
<div class="max-w-2xl mx-auto px-4 py-10 md:py-14">

    {{-- H1067 anti-urgency hero — no countdown, no "spots left", evergreen entry any day. --}}
    <div class="mb-10">
        <span class="inline-block text-[11px] font-extrabold uppercase tracking-wide text-[#E85C24] mb-3">
            Бесплатная консультация
        </span>
        <h1 class="text-3xl md:text-4xl font-black text-[#F1F5F9] leading-tight">
            {{ $heroTitle }}
        </h1>
        <span class="block w-12 h-[3px] bg-[#E85C24] rounded-full mt-4 mb-4" aria-hidden="true"></span>
        <p class="text-slate-300 text-lg font-semibold mb-2">
            {{ $heroSubtitle }}
        </p>
        <p class="text-sm text-slate-400">{{ $hostName }} · начать можно в любой день</p>
    </div>

    @if (session('marathon_result'))
        {{-- USEIT H1-2 — success pinned right under the hero, above fold, not lost below a scroll. --}}
        <div id="marathon-success"
             x-data="{ tgClicked: false, copied: false }"
             class="mb-8 p-6 bg-green-900 border border-green-800 rounded-2xl"
             aria-live="polite">
            <h3 class="font-extrabold text-green-200 text-lg mb-2">Вы записаны</h3>
            <p class="text-green-200 text-sm leading-relaxed mb-4">
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
                        class="w-full mt-2 px-6 py-2.5 border border-[#1F2636] text-slate-300 font-bold rounded-2xl hover:bg-white/5 transition-colors"
                        x-text="copied ? 'Ссылка скопирована' : 'Скопировать ссылку'">
                    Скопировать ссылку
                </button>
                {{-- USEIT H1-1/H9-2 — post-Telegram-click inline state, no silent click. --}}
                <div x-show="tgClicked" x-cloak
                     class="mt-3 p-3 bg-[#0088cc]/10 rounded-xl text-sm text-sky-200">
                    Открыли Telegram. Нажмите <b>Start</b> у бота — иначе дни не придут. Если окно не открылось — скопируйте ссылку выше или нажмите снова.
                </div>
            @endif

            <ol class="mt-4 pl-5 text-sm text-green-200 list-decimal space-y-1">
                <li>Start у бота</li>
                <li>Дождитесь сообщения Дня 1</li>
                <li>Канал новостей (по желанию):
                    <a href="{{ config('marathon.telegram_channel_url') }}" class="underline">{{ config('marathon.telegram_channel_url') }}</a>
                </li>
            </ol>
        </div>

        @if (session('marathon_track') === 'paid' && ! session('marathon_paid'))
            <div class="mb-8 p-6 bg-[#111622] border border-[#E85C24]/30 rounded-2xl">
                <p class="font-extrabold text-[#F1F5F9] mb-1">Шаг 2 из 2 — оплата трека «с проверкой»</p>
                <p class="text-sm text-slate-300 mb-4">
                    Оплатите {{ $paidTrackPrice }} ₽ — куратор будет проверять вашу практику Дней 1–2,
                    и вам гарантировано место на живой консультации Дня 3.
                </p>
                <form method="POST" action="{{ route('marathon.pay') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="contact" value="{{ session('marathon_contact') }}">
                    <div>
                        <label class="block text-sm font-bold text-slate-200 mb-1" for="marathon-pay-email">Email для чека</label>
                        <input id="marathon-pay-email" type="email" name="email" required
                               class="w-full rounded-xl bg-[#0A0D14] border-[#1F2636] text-slate-100 focus:border-[#E85C24] focus:ring-[#E85C24]">
                    </div>
                    @error('email')
                        <p class="text-sm text-red-400">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                        Оплатить {{ $paidTrackPrice }} ₽
                    </button>
                </form>
            </div>
        @endif
    @endif

    @if (session('error'))
        <div class="mb-8 p-4 bg-red-950/50 border border-red-900 rounded-2xl text-red-300 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if (!empty($days))
        {{-- USEIT H6-1 — vertical timeline, not a bare <ol>; orange nodes per packet A. --}}
        <section class="mb-10" aria-label="Три дня">
            <h2 class="text-lg font-extrabold text-[#F1F5F9] mb-4">Как устроены три дня</h2>
            <div class="border-l-2 border-[#1F2636] ml-2.5 pl-5 grid gap-[1.1rem]">
                @foreach ($days as $day)
                    <div class="relative">
                        <span class="absolute -left-[1.55rem] top-[0.35rem] w-[0.65rem] h-[0.65rem] rounded-full bg-[#E85C24] shadow-[0_0_0_3px_rgba(232,92,36,0.25)]" aria-hidden="true"></span>
                        <p class="font-extrabold text-[#F1F5F9] mb-1">{{ $day['title'] }}</p>
                        <p class="text-sm text-slate-300 leading-relaxed">{{ $day['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (!empty($benefits))
        <section class="mb-10">
            @if ($benefitsHeading !== '')
                <h2 class="text-lg font-extrabold text-[#F1F5F9] mb-4">{{ $benefitsHeading }}</h2>
            @endif
            <div class="grid gap-[0.65rem]">
                @foreach ($benefits as $benefit)
                    <div class="bg-[#111622] border border-[#1F2636] border-l-[3px] border-l-[#E85C24] rounded-2xl px-[1.1rem] py-4">
                        <p class="font-extrabold text-[#F1F5F9] mb-1">{{ $benefit['title'] }}</p>
                        <p class="text-sm text-slate-300 leading-relaxed">{{ $benefit['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($testimonial !== '')
        <blockquote class="mb-10 p-6 bg-[#111622] border-l-[3px] border-[#E85C24] rounded-r-2xl text-slate-300 italic">
            {{ $testimonial }}
        </blockquote>
    @endif

    <form method="POST" action="{{ route('marathon.register') }}"
          x-data="{ track: '{{ old('track', 'free') }}' }"
          class="bg-[#111622] rounded-[18px] border border-[#1F2636] shadow-lg shadow-black/20 p-6 md:p-8 space-y-6">
        @csrf

        <div>
            <label for="marathon-quiz" class="block text-sm font-extrabold text-slate-200 mb-2">Что вас привлекает в санскрите?</label>
            <select id="marathon-quiz" name="quiz_goal" required
                    class="w-full rounded-xl bg-[#0A0D14] border-[#1F2636] text-slate-100 focus:border-[#E85C24] focus:ring-[#E85C24]">
                <option value="" class="bg-[#0A0D14] text-slate-100">Выберите…</option>
                @foreach ($quizGoals as $key => $label)
                    <option value="{{ $key }}" class="bg-[#0A0D14] text-slate-100" @selected(old('quiz_goal') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('quiz_goal')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <fieldset class="border-0 p-0 m-0">
            <legend class="block text-sm font-extrabold text-slate-200 mb-2">Формат участия</legend>
            <div class="grid gap-2" @error('track') aria-describedby="track-error" @enderror>
                <label class="flex items-start gap-3 p-4 rounded-2xl border-[1.5px] cursor-pointer transition-colors"
                       :class="track === 'free' ? 'border-[#E85C24] bg-[#E85C24]/10' : 'border-[#1F2636]'">
                    <input type="radio" name="track" value="free" x-model="track" class="mt-1">
                    <span>
                        <span class="font-extrabold block text-[#F1F5F9]">Бесплатно</span>
                        <span class="text-sm text-slate-300">2 дня записей + маршрут + консультация в записи.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 p-4 rounded-2xl border-[1.5px] cursor-pointer transition-colors"
                       :class="track === 'paid' ? 'border-[#E85C24] bg-[#E85C24]/10' : 'border-[#1F2636]'">
                    <input type="radio" name="track" value="paid" x-model="track" class="mt-1">
                    <span>
                        <span class="font-extrabold block text-[#F1F5F9]">«С проверкой» — {{ $paidTrackPrice }} ₽</span>
                        <span class="text-sm text-slate-300">
                            Куратор смотрит вашу практику лично, разбор на живой консультации с {{ $hostName }},
                            скидка {{ $couponAmount }} ₽ на любой первый курс.
                        </span>
                    </span>
                </label>
            </div>
            @error('track')
                <p id="track-error" class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </fieldset>

        <div>
            <label for="marathon-name" class="block text-sm font-extrabold text-slate-200 mb-2">Имя <span class="text-red-400">*</span></label>
            <input id="marathon-name" type="text" name="name" required minlength="2" maxlength="255"
                   value="{{ old('name') }}"
                   class="w-full rounded-xl bg-[#0A0D14] border-[#1F2636] text-slate-100 focus:border-[#E85C24] focus:ring-[#E85C24]">
            @error('name')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="marathon-contact" class="block text-sm font-extrabold text-slate-200 mb-2">Контакт <span class="text-red-400">*</span></label>
            {{-- USEIT H5-1 — placeholder examples instead of a bare required field. --}}
            <input id="marathon-contact" type="text" name="contact" required value="{{ old('contact') }}"
                   placeholder="+7… / name@mail.ru / @username"
                   class="w-full rounded-xl bg-[#0A0D14] border-[#1F2636] text-slate-100 placeholder:text-slate-400 focus:border-[#E85C24] focus:ring-[#E85C24]">
            @error('contact')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full px-6 py-3.5 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
            {{ $cta }}
        </button>
        <p class="text-xs text-slate-400 text-center -mt-3">Без дедлайнов и «осталось N мест». Начать можно в любой день.</p>
    </form>

    @if (!empty($faq))
        <section class="mt-12 space-y-2.5" x-data="{ open: null }">
            <h2 class="text-lg font-extrabold text-[#F1F5F9] mb-3">Частые вопросы</h2>
            @foreach ($faq as $i => $item)
                <div class="bg-[#111622] rounded-2xl border border-[#1F2636] overflow-hidden">
                    <button type="button"
                            class="w-full text-left p-4 font-extrabold text-[#F1F5F9] flex justify-between gap-3"
                            :aria-expanded="(open === {{ $i }}).toString()"
                            aria-controls="faq-panel-{{ $i }}"
                            @click="open === {{ $i }} ? open = null : open = {{ $i }}">
                        <span>{{ $item['q'] }}</span>
                        <span class="text-[#E85C24]" aria-hidden="true" x-text="open === {{ $i }} ? '−' : '+'"></span>
                    </button>
                    <div id="faq-panel-{{ $i }}" class="px-4 pb-4 text-sm text-slate-300 leading-relaxed" x-show="open === {{ $i }}" x-cloak>
                        {{ $item['a'] }}
                    </div>
                </div>
            @endforeach
        </section>
    @endif

</div>
