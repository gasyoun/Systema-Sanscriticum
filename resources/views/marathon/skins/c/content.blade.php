{{-- H1977 — skin C, warm paper (O2). Concurrent sibling with A/B/D
     (H1966 multi-dir policy), not a sole winner. Tokens + wireframe from
     marketing/marathon-2026-08/redesign/direction-c-paper.html and the
     packet's Direction C section; USEIT Must-fix set folded in inline
     (see marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md).
     Layout choice: O2 light island under the dark shop header (same break
     pattern as skin B), not O3 layouts/marathon — reuses the proven full-
     bleed wrap instead of a second layout file, documented per the handoff's
     "pick one, document in PR" instruction. Accent is the packet's own
     terracotta #C45C26, not the shop's #E85C24 — the aesthetic lock reads
     "no pure-shop-orange if terracotta is the lock", so this skin does not
     borrow B/A's CTA color. Aesthetic lock: editorial warm, serif display +
     sans body, max ONE decorative mark per day card (the ◆ glyph below),
     no museum overload. --}}
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
<div class="min-h-screen bg-[#F7F1E8] text-[#2C2416]">
    <div class="max-w-2xl mx-auto px-4 py-11 md:py-14">

        {{-- H1067 anti-urgency hero — no countdown, no "spots left", evergreen entry any day. --}}
        <div class="mb-9">
            {{-- WCAG: text-[#C45C26] on bg #F7F1E8 computes to 3.81:1, below the
                 4.5:1 normal-text AA floor at this 11px size — bumped to the
                 darker in-family #A94D1F (4.96:1, already the file's own CTA
                 hover shade) rather than inventing a new hue. --}}
            <span class="inline-block text-[11px] font-extrabold uppercase tracking-wide text-[#A94D1F] mb-3">
                Бесплатная консультация
            </span>
            <h1 class="font-serif text-3xl md:text-4xl font-bold text-[#2C2416] leading-[1.2]">
                {{ $heroTitle }}
            </h1>
            <hr class="border-0 border-t border-[#E0D4C4] my-5" aria-hidden="true">
            <p class="text-[#6B5E4E] text-lg leading-relaxed mb-2">
                {{ $heroSubtitle }}
            </p>
            <p class="text-sm text-[#6B5E4E]">{{ $hostName }} · начать можно в любой день</p>
        </div>

        @if (session('marathon_result'))
            {{-- USEIT H1-2 — success pinned right under the hero, above fold, not lost below a scroll. --}}
            <div id="marathon-success"
                 x-data="{ tgClicked: false, copied: false }"
                 class="mb-8 p-6 bg-emerald-50 border border-emerald-200 rounded-xl"
                 aria-live="polite">
                <h3 class="font-serif font-bold text-emerald-800 text-lg mb-2">Вы записаны</h3>
                <p class="text-emerald-800 text-sm leading-relaxed mb-4">
                    Чтобы получить личный День 1 и День 2, нажмите кнопку и запустите бота — без этого шага дни не придут.
                </p>

                @if (session('marathon_telegram_link'))
                    <a href="{{ session('marathon_telegram_link') }}" target="_blank" rel="noopener"
                       @click="tgClicked = true"
                       class="block text-center bg-[#0088cc] hover:bg-[#0077b3] text-white font-extrabold px-6 py-3 rounded-xl transition-colors">
                        Продолжить в Telegram
                    </a>
                    <button type="button"
                            @click="navigator.clipboard.writeText(@js(session('marathon_telegram_link'))).then(() => copied = true)"
                            class="w-full mt-2 px-6 py-2.5 border border-[#E0D4C4] text-[#6B5E4E] font-bold rounded-xl hover:bg-white/60 transition-colors"
                            x-text="copied ? 'Ссылка скопирована' : 'Скопировать ссылку'">
                        Скопировать ссылку
                    </button>
                    {{-- USEIT H1-1/H9-2 — post-Telegram-click inline state, no silent click. --}}
                    <div x-show="tgClicked" x-cloak
                         class="mt-3 p-3 bg-sky-50 rounded-lg text-sm text-sky-900">
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
                <div class="mb-8 p-6 bg-[#FFFCF7] border border-[#E0D4C4] rounded-xl">
                    <p class="font-serif font-bold text-[#2C2416] mb-1">Шаг 2 из 2 — оплата трека «с проверкой»</p>
                    <p class="text-sm text-[#6B5E4E] mb-4">
                        Оплатите {{ $paidTrackPrice }} ₽ — куратор будет проверять вашу практику Дней 1–2,
                        и вам гарантировано место на живой консультации Дня 3.
                    </p>
                    <form method="POST" action="{{ route('marathon.pay') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="contact" value="{{ session('marathon_contact') }}">
                        <div>
                            <label class="block text-sm font-bold text-[#2C2416] mb-1" for="marathon-pay-email">Email для чека</label>
                            <input id="marathon-pay-email" type="email" name="email" required
                                   class="w-full rounded-lg border-[#D4C8B8] text-[#2C2416] bg-white focus:border-[#C45C26] focus:ring-[#C45C26]">
                        </div>
                        @error('email')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="w-full px-6 py-3 bg-[#A94D1F] hover:bg-[#8B3E19] text-white font-extrabold rounded-lg transition-colors">
                            Оплатить {{ $paidTrackPrice }} ₽
                        </button>
                    </form>
                </div>
            @endif
        @endif

        @if (session('error'))
            <div class="mb-8 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @if (!empty($days))
            {{-- USEIT H6-1 — lesson cards, not a bare <ol>; one ◆ mark per card (aesthetic-lock cap). --}}
            <section class="mb-9" aria-label="Три дня">
                <h2 class="text-sm font-extrabold uppercase tracking-wide text-[#2C2416] mb-3">Как устроены три дня</h2>
                <div class="grid gap-3">
                    @foreach ($days as $day)
                        <div class="bg-[#FFFCF7] border border-[#E0D4C4] rounded-[10px] p-4">
                            <p class="font-serif font-bold text-[#2C2416] mb-1">
                                <span class="text-[#C45C26] mr-1" aria-hidden="true">&#9670;</span>{{ $day['title'] }}
                            </p>
                            <p class="text-sm text-[#6B5E4E] leading-relaxed">{{ $day['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (!empty($benefits))
            <section class="mb-9">
                @if ($benefitsHeading !== '')
                    <h2 class="text-sm font-extrabold uppercase tracking-wide text-[#2C2416] mb-3">{{ $benefitsHeading }}</h2>
                @endif
                <div class="divide-y divide-[#E0D4C4]">
                    @foreach ($benefits as $benefit)
                        <div class="py-3.5">
                            <p class="font-serif font-bold text-[#2C2416] mb-0.5">{{ $benefit['title'] }}</p>
                            <p class="text-sm text-[#6B5E4E] leading-relaxed">{{ $benefit['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- H1067 gate — real testimonial only, never fabricated (Must-not). --}}
        @if ($testimonial !== '')
            <blockquote class="mb-9 pl-4 py-1 border-l-[3px] border-[#C45C26] text-[#6B5E4E] italic leading-relaxed">
                {{ $testimonial }}
            </blockquote>
        @endif

        <form method="POST" action="{{ route('marathon.register') }}"
              x-data="{ track: '{{ old('track', 'free') }}' }"
              class="bg-[#FFFCF7] rounded-xl border border-[#E0D4C4] p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <label for="marathon-quiz" class="block text-sm font-extrabold text-[#2C2416] mb-2">Что вас привлекает в санскрите?</label>
                <select id="marathon-quiz" name="quiz_goal" required
                        class="w-full rounded-lg border-[#D4C8B8] text-[#2C2416] bg-white focus:border-[#C45C26] focus:ring-[#C45C26]">
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
                <legend class="block text-sm font-extrabold text-[#2C2416] mb-2">Формат участия</legend>
                <div class="grid gap-2" @error('track') aria-describedby="track-error" @enderror>
                    <label class="flex items-start gap-3 p-4 rounded-lg border-[1.5px] cursor-pointer transition-colors bg-white"
                           :class="track === 'free' ? 'border-[#C45C26] bg-[#FAF3EB]' : 'border-[#E0D4C4]'">
                        <input type="radio" name="track" value="free" x-model="track" class="mt-1">
                        <span>
                            <span class="font-extrabold block text-[#2C2416]">Бесплатно</span>
                            <span class="text-sm text-[#6B5E4E]">2 дня записей + маршрут + консультация в записи.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 p-4 rounded-lg border-[1.5px] cursor-pointer transition-colors bg-white"
                           :class="track === 'paid' ? 'border-[#C45C26] bg-[#FAF3EB]' : 'border-[#E0D4C4]'">
                        <input type="radio" name="track" value="paid" x-model="track" class="mt-1">
                        <span>
                            <span class="font-extrabold block text-[#2C2416]">«С проверкой» — {{ $paidTrackPrice }} ₽</span>
                            <span class="text-sm text-[#6B5E4E]">
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
                <label for="marathon-name" class="block text-sm font-extrabold text-[#2C2416] mb-2">Имя <span class="text-red-500">*</span></label>
                <input id="marathon-name" type="text" name="name" required minlength="2" maxlength="255"
                       value="{{ old('name') }}"
                       class="w-full rounded-lg border-[#D4C8B8] text-[#2C2416] bg-white focus:border-[#C45C26] focus:ring-[#C45C26]">
                @error('name')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="marathon-contact" class="block text-sm font-extrabold text-[#2C2416] mb-2">Контакт <span class="text-red-500">*</span></label>
                {{-- USEIT H5-1 — placeholder examples instead of a bare required field. --}}
                <input id="marathon-contact" type="text" name="contact" required value="{{ old('contact') }}"
                       placeholder="+7… / name@mail.ru / @username"
                       class="w-full rounded-lg border-[#D4C8B8] text-[#2C2416] placeholder:text-[#6B5E4E] bg-white focus:border-[#C45C26] focus:ring-[#C45C26]">
                @error('contact')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full px-6 py-3.5 bg-[#A94D1F] hover:bg-[#8B3E19] text-white font-extrabold rounded-lg transition-colors">
                {{ $cta }}
            </button>
            <p class="text-xs text-[#6B5E4E] text-center -mt-3">Без дедлайнов и «осталось N мест». Начать можно в любой день.</p>
        </form>

        @if (!empty($faq))
            {{-- Wireframe step 7 — thin rules, not boxed cards (skin C's own texture, distinct from B). --}}
            <section class="mt-12" x-data="{ open: null }">
                <h2 class="text-sm font-extrabold uppercase tracking-wide text-[#2C2416] mb-1">Частые вопросы</h2>
                @foreach ($faq as $i => $item)
                    <div class="border-t border-[#E0D4C4] py-3.5">
                        <button type="button"
                                class="w-full text-left font-extrabold text-[#2C2416] flex justify-between gap-3"
                                :aria-expanded="(open === {{ $i }}).toString()"
                                aria-controls="faq-panel-{{ $i }}"
                                @click="open === {{ $i }} ? open = null : open = {{ $i }}">
                            <span>{{ $item['q'] }}</span>
                            <span class="text-[#C45C26]" aria-hidden="true" x-text="open === {{ $i }} ? '−' : '+'"></span>
                        </button>
                        <div id="faq-panel-{{ $i }}" class="pt-2 text-sm text-[#6B5E4E] leading-relaxed" x-show="open === {{ $i }}" x-cloak>
                            {{ $item['a'] }}
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

    </div>
</div>
