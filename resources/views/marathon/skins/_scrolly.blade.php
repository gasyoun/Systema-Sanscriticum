{{--
  H4521 — scrollytelling block «Как проходит консультация» (skin b only).

  Storyboard (MG-read gate): marketing/marathon-2026-08/redesign/
  STORYBOARD_konsultaciya-scrolly_10.09.26.md

  Flag: config('marathon_visual.scrollytelling') — default FALSE (copy A/B is
  live until 2026-11-01, this block is structural and must not move that axis).
  QA override: ?scrolly=1 — resolved here, never persisted.

  Motion discipline (SCROLLYTELLING_STORYBOARD_TEMPLATE.md hard rules):
  static first; IntersectionObserver + CSS `sticky` only, NO new dependency;
  every beat is a normal document section, so a no-JS reader gets the same text
  and order with no reveal animation (CSS sticky is layout, not JS — it still
  applies); prefers-reduced-motion drops stickiness and transitions entirely.

  Copy discipline: no new marketing claims — every string below is existing
  approved copy from config/marathon_landing_copy.php ($days, $faq, $tracks,
  $quizGoals) or a config number ($paidTrackPrice, $couponAmount). The copy
  A/B config file is not touched.
--}}
@php
    $scrollyOn = (bool) config('marathon_visual.scrollytelling', false)
        || in_array(strtolower((string) request()->query('scrolly', '')), ['1', 'true', 'on', 'yes'], true);

    $scrollyDays = $days ?? [];
    $scrollyQuizGoals = $quizGoals ?? [];
    $scrollyFaq = $faq ?? [];
    $scrollyTracks = ($copy['tracks'] ?? []);
    $scrollyCta = $cta ?? 'Записаться';
    // FAQ rows are the shared (A/B-independent) config block. Indices:
    // [0] «Сколько это занимает времени?» → schedule objection (22.6 %)
    // [1] «Нужно ли знать деванагари…»    → level/entry objection
    // [2] «Что будет на живой консультации Дня 3?» → route after the course
    $scrollySchedule = $scrollyFaq[0]['a'] ?? '';
    $scrollyEntry = $scrollyFaq[1]['a'] ?? '';
    $scrollyRoute = $scrollyFaq[2]['a'] ?? '';
@endphp
@if ($scrollyOn)
<section id="scrolly-konsultaciya" class="scrolly mb-10" aria-labelledby="scrolly-heading">
    <h2 id="scrolly-heading" class="text-lg font-extrabold text-stone-900 mb-3">Как проходит консультация</h2>

    <div class="space-y-4">
        {{-- Beat 1 — «Не знаете свой уровень…»: the level-quiz objection. --}}
        <article class="scrolly-beat bg-white border border-stone-200 rounded-2xl p-5"
                 data-scrolly-beat="1" aria-labelledby="scrolly-b1">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-brand mb-1">Шаг 1</p>
            <h3 id="scrolly-b1" class="font-extrabold text-stone-900 text-lg mb-2 leading-snug">
                Не знаете свой уровень — это и есть повод прийти
            </h3>
            @if ($scrollyEntry !== '')
                <p class="text-sm text-stone-600 leading-relaxed mb-4">{{ $scrollyEntry }}</p>
            @endif
            {{-- Static illustration of the landing's own quiz (labels = approved
                 quizGoals copy, no data, no PII). Decorative: the real control
                 is the form select below, so it is hidden from assistive tech. --}}
            <figure class="rounded-2xl border border-stone-200 bg-stone-50 p-4" aria-hidden="true">
                <div class="rounded-xl bg-white border border-stone-200 p-3">
                    <p class="text-xs font-bold text-stone-500 mb-2">Что вас привлекает в санскрите?</p>
                    <ul class="space-y-1.5">
                        @foreach ($scrollyQuizGoals as $key => $label)
                            <li class="text-sm rounded-lg px-3 py-2 border {{ $loop->first ? 'border-brand bg-orange-50 font-bold text-stone-900' : 'border-stone-200 text-stone-600' }}">{{ $label }}</li>
                        @endforeach
                    </ul>
                </div>
            </figure>
        </article>

        {{-- Beat 2 — the three days, verbatim from config, stacked sticky. --}}
        <article class="scrolly-beat bg-white border border-stone-200 rounded-2xl p-5"
                 data-scrolly-beat="2" aria-labelledby="scrolly-b2">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-brand mb-1">Шаг 2</p>
            <h3 id="scrolly-b2" class="font-extrabold text-stone-900 text-lg mb-3 leading-snug">
                Три дня: что реально будет
            </h3>
            <div class="space-y-3">
                @foreach ($scrollyDays as $i => $day)
                    <div class="scrolly-day rounded-2xl border border-stone-200 bg-white p-4"
                         style="top: {{ 0.75 + $i * 0.75 }}rem">
                        <p class="font-extrabold text-stone-900 mb-0.5">{{ $day['title'] }}</p>
                        <p class="text-sm text-stone-600 leading-relaxed">{{ $day['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </article>

        {{-- Beat 3 — after the consultation: route / price / schedule. --}}
        <article class="scrolly-beat bg-white border border-stone-200 rounded-2xl p-5"
                 data-scrolly-beat="3" aria-labelledby="scrolly-b3">
            <p class="text-[11px] font-extrabold uppercase tracking-wide text-brand mb-1">Шаг 3</p>
            <h3 id="scrolly-b3" class="font-extrabold text-stone-900 text-lg mb-3 leading-snug">
                После консультации: маршрут, цена, расписание
            </h3>
            <div class="scrolly-tiles grid gap-3">
                @if ($scrollyRoute !== '')
                    <div class="rounded-2xl border border-stone-200 bg-stone-50 p-4">
                        <p class="text-xs font-extrabold uppercase tracking-wide text-stone-500 mb-1">Маршрут</p>
                        <p class="text-sm text-stone-700 leading-relaxed">{{ $scrollyRoute }}</p>
                    </div>
                @endif
                <div class="rounded-2xl border border-stone-200 bg-stone-50 p-4">
                    <p class="text-xs font-extrabold uppercase tracking-wide text-stone-500 mb-1">Цена</p>
                    @php $scrollyFree = $scrollyTracks['free'] ?? []; $scrollyPaid = $scrollyTracks['paid'] ?? []; @endphp
                    @if (!empty($scrollyFree))
                        <p class="text-sm text-stone-700 leading-relaxed mb-2">
                            <span class="font-extrabold text-stone-900">{{ $scrollyFree['title'] ?? '' }}</span>
                            @if (!empty($scrollyFree['body'])) — {{ $scrollyFree['body'] }} @endif
                        </p>
                    @endif
                    @if (!empty($scrollyPaid))
                        <p class="text-sm text-stone-700 leading-relaxed">
                            <span class="font-extrabold text-stone-900">{{ $scrollyPaid['title'] ?? '' }} —
                                {{ $paidTrackPrice ?? 0 }} ₽</span>
                            @if (!empty($scrollyPaid['body'])) — {{ $scrollyPaid['body'] }} @endif
                            <span class="whitespace-nowrap">Скидка {{ $couponAmount ?? 0 }} ₽ на любой первый курс.</span>
                        </p>
                    @endif
                </div>
                @if ($scrollySchedule !== '')
                    <div class="rounded-2xl border border-stone-200 bg-stone-50 p-4">
                        <p class="text-xs font-extrabold uppercase tracking-wide text-stone-500 mb-1">Расписание</p>
                        <p class="text-sm text-stone-700 leading-relaxed">{{ $scrollySchedule }}</p>
                    </div>
                @endif
            </div>
        </article>

        {{-- Beat 4 — CTA (static; the form anchor is the block's only action). --}}
        <article class="scrolly-beat bg-orange-50 border border-orange-200 rounded-2xl p-5 text-center"
                 data-scrolly-beat="4" aria-labelledby="scrolly-b4">
            <h3 id="scrolly-b4" class="font-extrabold text-stone-900 text-lg mb-3">Записаться на консультацию</h3>
            <a href="#marathon-form"
               class="inline-block bg-brand hover:bg-brand-hover text-white font-extrabold px-6 py-3 rounded-2xl transition-colors">
                {{ $scrollyCta }}
            </a>
        </article>
    </div>

    {{-- Rule 6 — the CTA never scrolls away while the block is on screen.
         Static (just the last line) without CSS sticky / under reduced motion. --}}
    <div class="scrolly-cta sticky bottom-0 z-20 mt-3 rounded-2xl border border-stone-200 bg-white/95 px-4 py-3 flex items-center justify-between gap-3">
        <span class="text-xs text-stone-500">Бесплатная консультация · 3 дня, ~15 минут в день</span>
        <a href="#marathon-form"
           class="shrink-0 bg-brand hover:bg-brand-hover text-white text-sm font-extrabold px-4 py-2 rounded-xl transition-colors">
            {{ $scrollyCta }}
        </a>
    </div>
</section>

@push('head')
<style>
    /* H4521 — reveal animation is opt-in: without JS (`.scrolly-js` is only
       added by the script below) the beats are plain sections, same text and
       order, no reveal. CSS `sticky` is layout and still applies; only
       `prefers-reduced-motion` turns stickiness off. */
    .scrolly-beat { transition: opacity .45s ease, transform .45s ease; }
    .scrolly-js .scrolly-beat:not(.is-in) { opacity: 0; transform: translateY(14px); }
    .scrolly-day { position: sticky; z-index: 1; }
    .scrolly-tiles { position: sticky; top: .5rem; z-index: 2; }
    /* Anchor offset for the CTA targets — inline so this block adds no new
       Tailwind utility (deploy.sh skips the npm build on blade-only diffs). */
    #marathon-form { scroll-margin-top: 5rem; }

    /* Rule 5 — reduced motion: stickiness and transforms off, beats stack. */
    @media (prefers-reduced-motion: reduce) {
        .scrolly-beat,
        .scrolly-js .scrolly-beat:not(.is-in) { transition: none; opacity: 1; transform: none; }
        .scrolly-day,
        .scrolly-tiles,
        .scrolly-cta { position: static; }
    }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        var root = document.getElementById('scrolly-konsultaciya');
        if (!root) return;

        // Progressive enhancement: motion only when JS is available.
        document.documentElement.classList.add('scrolly-js');

        // Reuse the shop Metrika helper (partials/shop-metrika.blade.php);
        // the handoff names this entry point `window.reachGoal`.
        window.reachGoal = window.reachGoal || window.shopReachGoal || function () {};

        var beats = root.querySelectorAll('[data-scrolly-beat]');
        var seen = {};

        function reveal(el) {
            el.classList.add('is-in');
            var step = el.getAttribute('data-scrolly-beat');
            if (step && step !== '4' && !seen[step]) {
                seen[step] = true;
                try { window.reachGoal('scrolly_step_' + step); } catch (e) { /* never break the page */ }
            }
        }

        if (!('IntersectionObserver' in window)) {
            Array.prototype.forEach.call(beats, reveal);
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    reveal(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.25 });

        Array.prototype.forEach.call(beats, function (el) { io.observe(el); });
    })();
</script>
@endpush
@endif
