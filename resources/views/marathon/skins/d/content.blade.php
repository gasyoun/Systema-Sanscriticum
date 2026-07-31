{{-- H1978 — skin D, conversion-first stepped Alpine flow (O2). Concurrent
     sibling with A/B/C (H1966 multi-dir policy), not a sole winner. Tokens +
     wireframe from marketing/marathon-2026-08/redesign/direction-d-stepped.html
     and the packet's Direction D section; reuses B's light-island tokens
     (packet: "reuse B tokens"), progress accent #E85C24. USEIT H3-2 (multi-
     step needs back + edit) folded in via the progress list + Назад buttons
     below (see marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md). --}}
@php
    /** @var array $copy H1067 — default A, switch B via MARATHON_LANDING_COPY_VARIANT (copy axis, independent of this skin) */
    $v = $copy['variant'] ?? [];
    $heroTitle = $v['hero_title'] ?? 'Пойми, как устроен санскрит, и выбери свой курс';
    $days = $copy['days'] ?? [];
    $faq = $copy['faq'] ?? [];

    // Land on step 4 after a successful register() redirect (H1975 acceptance:
    // "not step 1 empty form"); on a server-side validation error, reopen
    // whichever step actually owns the invalid field instead of resetting to
    // step 1 — client-side `:disabled` mostly prevents this, but Laravel can
    // still reject a submit (e.g. a JS-disabled client).
    $initialStep = 1;
    if (session('marathon_result')) {
        $initialStep = 4;
    } elseif ($errors->has('name') || $errors->has('contact')) {
        $initialStep = 3;
    } elseif ($errors->has('track')) {
        $initialStep = 2;
    }
@endphp
<div class="min-h-screen bg-stone-50 text-stone-900"
     x-data="{
        step: {{ $initialStep }},
        quizGoal: '{{ old('quiz_goal') }}',
        track: '{{ old('track', 'free') }}',
        name: '{{ old('name') }}',
        contact: '{{ old('contact') }}',
        tgClicked: false,
        copied: false,
        labels: ['', 'цель', 'формат', 'контакт', 'готово'],
        init() {
            this.$watch('step', () => this.$nextTick(() => this.$refs['h' + this.step] && this.$refs['h' + this.step].focus()));
        },
     }">
    <div class="max-w-md mx-auto px-4 py-8 md:py-12">

        {{-- Compact hero strip (wireframe item 1) — not literally CSS-fixed;
             the mockup itself renders it as a plain in-flow strip above the
             steps, not `position: fixed`. --}}
        <div class="text-center mb-5">
            <span class="inline-block text-[11px] font-extrabold uppercase tracking-wide text-[#E85C24] bg-orange-50 rounded-md px-2 py-1 mb-2">
                Бесплатная консультация · ~15 мин/день
            </span>
            <h1 class="text-2xl font-black text-stone-900 leading-tight">
                {{ $heroTitle }}
            </h1>
        </div>

        {{-- Progress: segmented bar with a real aria-current step list (USEIT H3-2 / wireframe item 2), plus a live-announced text label. --}}
        <nav aria-label="Прогресс регистрации" class="mb-5">
            <ol role="list" class="flex gap-1.5">
                <template x-for="n in 4" :key="n">
                    <li class="h-1.5 flex-1 rounded-full transition-colors"
                        :class="step >= n ? 'bg-[#E85C24]' : 'bg-stone-200'"
                        :aria-current="step === n ? 'step' : null">
                        <span class="sr-only" x-text="'Шаг ' + n + ' из 4 — ' + labels[n]"></span>
                    </li>
                </template>
            </ol>
            <p class="mt-2 text-xs font-bold text-stone-500" aria-live="polite" x-text="'Шаг ' + step + ' из 4 · ' + labels[step]"></p>
        </nav>

        @if (session('error'))
            <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('marathon.register') }}">
            @csrf

            {{-- Step 1 — intent quiz only. --}}
            <div x-show="step === 1" x-cloak class="bg-white rounded-[20px] shadow-sm border border-stone-200 p-6 space-y-4" role="group" aria-labelledby="step1-h">
                <h2 id="step1-h" x-ref="h1" tabindex="-1" class="text-lg font-extrabold text-stone-900 outline-none focus-visible:ring-2 focus-visible:ring-[#E85C24] focus-visible:ring-offset-2 rounded">Что вас привлекает в санскрите?</h2>
                {{-- Radio cards (not B/C's `<select>`) — matches the D mockup's tap-target
                     cards and the "one decision per screen" thesis; same `quiz_goal` field
                     name/values as B/C, just a different widget for a full-screen step. --}}
                <fieldset class="border-0 p-0 m-0" @error('quiz_goal') aria-describedby="quiz-goal-error" @enderror>
                    <legend class="sr-only">Что вас привлекает в санскрите?</legend>
                    <div class="grid gap-2">
                        @foreach ($quizGoals as $key => $label)
                            <label class="flex items-center gap-3 p-3.5 rounded-2xl border-[1.5px] cursor-pointer transition-colors"
                                   :class="quizGoal === '{{ $key }}' ? 'border-[#E85C24] bg-orange-50' : 'border-stone-200'">
                                <input type="radio" name="quiz_goal" value="{{ $key }}" x-model="quizGoal" required class="shrink-0">
                                <span class="font-bold text-stone-900">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @error('quiz_goal')
                    <p id="quiz-goal-error" class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                <button type="button" :disabled="!quizGoal" @click="step = 2"
                        class="w-full px-6 py-3.5 bg-[#E85C24] hover:bg-[#d34f1c] disabled:opacity-40 disabled:cursor-not-allowed text-white font-extrabold rounded-xl transition-colors">
                    Далее
                </button>
                @if (!empty($days))
                    <p class="text-xs text-stone-500 leading-relaxed pt-1">
                        @foreach ($days as $i => $day)
                            День {{ $i + 1 }} — {{ $day['title'] }}{{ ! $loop->last ? ' · ' : '' }}
                        @endforeach
                    </p>
                @endif
            </div>

            {{-- Step 2 — track radios. --}}
            <div x-show="step === 2" x-cloak class="bg-white rounded-[20px] shadow-sm border border-stone-200 p-6 space-y-4" role="group" aria-labelledby="step2-h">
                <h2 id="step2-h" x-ref="h2" tabindex="-1" class="text-lg font-extrabold text-stone-900 outline-none focus-visible:ring-2 focus-visible:ring-[#E85C24] focus-visible:ring-offset-2 rounded">Формат участия</h2>
                <fieldset class="border-0 p-0 m-0" @error('track') aria-describedby="track-error" @enderror>
                    <legend class="sr-only">Формат участия</legend>
                    <div class="grid gap-2">
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
                </fieldset>
                @error('track')
                    <p id="track-error" class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex gap-2">
                    <button type="button" @click="step = 1" class="px-5 py-3 border border-stone-300 text-stone-600 font-bold rounded-xl hover:bg-stone-100 transition-colors">Назад</button>
                    <button type="button" @click="step = 3" class="flex-1 px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">Далее</button>
                </div>
            </div>

            {{-- Step 3 — contact + name → final submit. --}}
            <div x-show="step === 3" x-cloak class="bg-white rounded-[20px] shadow-sm border border-stone-200 p-6 space-y-4" role="group" aria-labelledby="step3-h">
                <h2 id="step3-h" x-ref="h3" tabindex="-1" class="text-lg font-extrabold text-stone-900 outline-none focus-visible:ring-2 focus-visible:ring-[#E85C24] focus-visible:ring-offset-2 rounded">Куда прислать День 1?</h2>
                <div>
                    <label for="marathon-name" class="block text-sm font-extrabold text-stone-800 mb-1.5">Имя <span class="text-red-500">*</span></label>
                    <input id="marathon-name" type="text" name="name" required minlength="2" maxlength="255" x-model="name"
                           class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                    @error('name')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="marathon-contact" class="block text-sm font-extrabold text-stone-800 mb-1.5">Контакт <span class="text-red-500">*</span></label>
                    <input id="marathon-contact" type="text" name="contact" required x-model="contact"
                           placeholder="+7… / name@mail.ru / @username"
                           class="w-full rounded-xl border-stone-300 text-stone-900 bg-white focus:border-[#E85C24] focus:ring-[#E85C24]">
                    @error('contact')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="step = 2" class="px-5 py-3 border border-stone-300 text-stone-600 font-bold rounded-xl hover:bg-stone-100 transition-colors">Назад</button>
                    <button type="submit" :disabled="name.trim().length < 2 || !contact.trim()"
                            class="flex-1 px-6 py-3.5 bg-[#E85C24] hover:bg-[#d34f1c] disabled:opacity-40 disabled:cursor-not-allowed text-white font-extrabold rounded-xl transition-colors">
                        Записаться
                    </button>
                </div>
                <p class="text-xs text-stone-500 text-center">Без дедлайнов и «осталось N мест». Начать можно в любой день.</p>
            </div>

            {{-- Step 4 — success. Post-TG-click state lives entirely here (USEIT H1-1/H9-2). --}}
            @if (session('marathon_result'))
                <div x-show="step === 4" x-cloak id="marathon-success" class="bg-white rounded-[20px] shadow-sm border border-stone-200 p-6 text-center" aria-live="polite" role="group" aria-labelledby="step4-h">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-emerald-50 text-emerald-800 flex items-center justify-center text-2xl font-black" aria-hidden="true">✓</div>
                    <h2 id="step4-h" x-ref="h4" tabindex="-1" class="font-extrabold text-emerald-800 text-lg mb-2 outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 rounded">Вы записаны</h2>
                    <p class="text-emerald-800 text-sm leading-relaxed mb-4 text-left">
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
                        <div x-show="tgClicked" x-cloak class="mt-3 p-3 bg-sky-50 rounded-xl text-sm text-sky-900 text-left">
                            Открыли Telegram. Нажмите <b>Start</b> у бота — иначе дни не придут. Если окно не открылось — скопируйте ссылку выше или нажмите снова.
                        </div>
                    @endif

                    <ol class="mt-4 pl-5 text-sm text-emerald-800 list-decimal space-y-1 text-left">
                        <li>Start у бота</li>
                        <li>Дождитесь сообщения Дня 1</li>
                        <li>Канал новостей (по желанию):
                            <a href="{{ config('marathon.telegram_channel_url') }}" class="underline">{{ config('marathon.telegram_channel_url') }}</a>
                        </li>
                    </ol>

                    @if (session('marathon_track') === 'paid' && ! session('marathon_paid'))
                        <div class="mt-5 p-5 bg-orange-50 border border-orange-200 rounded-2xl text-left">
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
                </div>
            @endif
        </form>

        @if (!empty($faq))
            <section class="mt-8 space-y-2.5" x-data="{ open: null }">
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
