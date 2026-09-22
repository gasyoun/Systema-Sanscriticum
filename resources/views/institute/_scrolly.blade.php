{{--
  H4522 — donor impact scroll «Куда идут 500 ₽» on /mecenaty.

  Storyboard (MG-read gate, lifted 14-09-2026): marketing/marathon-2026-08/
  redesign/STORYBOARD_mecenaty-impact_10.09.26.md

  Flag: config('institute.mecenaty_scrolly') — default FALSE (money contour:
  dark deploy, prod flip is a human MG row after QA screenshots).
  QA override: ?impact=1 — resolved here, never persisted.

  Motion discipline (house pattern: resources/views/marathon/skins/_scrolly.blade.php):
  static first; IntersectionObserver + CSS `sticky` only, NO new dependency;
  every beat is a normal document section, so a no-JS reader gets the same
  text and order; prefers-reduced-motion drops stickiness and transitions.

  Copy discipline: no new claims — every string is existing approved copy
  (mecenaty.blade.php H4400 block / INSTITUTE_MONETIZATION_PLAN_2026H2.md
  §«Меценаты»). Gratitude beat: names only, never amounts (DonationGratitude
  rules); consent flag respected via the same publicList scope as the page.

  Donor frame ст. 582 ГК wording lives in mecenaty.blade.php and is NOT
  touched by this partial — the CTA beat only points to it.
--}}
@php
    $impactOn = (bool) config('institute.mecenaty_scrolly', false)
        || in_array(strtolower((string) request()->query('impact', '')), ['1', 'true', 'on', 'yes'], true);

    // Same public list as the page's own gratitude block (old ones first).
    $impactGratitudes = isset($gratitudes) ? $gratitudes : collect();
@endphp
@if ($impactOn)
<section id="impact-scroll" class="mb-12" aria-labelledby="impact-heading">
    <h2 id="impact-heading" class="text-2xl font-bold text-white mb-4">Куда идут 500 ₽</h2>

    <div class="space-y-4">
        {{-- Beat 1 — «Институт исследования санскрита — что это»: approved intro wording. --}}
        <article class="impact-beat rounded-xl border border-slate-700 p-5"
                 data-impact-beat="1" aria-labelledby="impact-b1">
            <p class="text-xs font-bold uppercase tracking-wide text-brand mb-1">Институт</p>
            <h3 id="impact-b1" class="text-lg font-bold text-white mb-2">
                Институт исследования санскрита — что это
            </h3>
            <p class="text-slate-300 leading-relaxed">
                Учебное крыло Общества ревнителей санскрита. Меценатская поддержка
                идет на исследовательскую работу: корпуса и словари, издания,
                открытые разборы и бесплатные открытые занятия.
            </p>
            <ul class="mt-3 flex flex-wrap gap-2 text-sm text-slate-200">
                <li class="rounded-lg border border-slate-600 px-3 py-1">Словари</li>
                <li class="rounded-lg border border-slate-600 px-3 py-1">Корпуса</li>
                <li class="rounded-lg border border-slate-600 px-3 py-1">Открытые разборы</li>
            </ul>
        </article>

        {{-- Beat 2 — «Что уже сделано»: three direction tiles (sticky stack).
             Facts verbatim from the page/plan; no invented numbers. --}}
        <article class="impact-beat rounded-xl border border-slate-700 p-5"
                 data-impact-beat="2" aria-labelledby="impact-b2">
            <p class="text-xs font-bold uppercase tracking-wide text-brand mb-1">Факты</p>
            <h3 id="impact-b2" class="text-lg font-bold text-white mb-3">Что уже сделано</h3>
            <div class="space-y-3">
                <div class="impact-tile rounded-xl border border-slate-700 bg-slate-900/60 p-4" style="top: 4rem">
                    <p class="font-bold text-white mb-0.5">Корпуса и словари</p>
                    <p class="text-sm text-slate-300 leading-relaxed">Исследовательская работа Института: корпуса и словари.</p>
                </div>
                <div class="impact-tile rounded-xl border border-slate-700 bg-slate-900/60 p-4" style="top: 4.75rem">
                    <p class="font-bold text-white mb-0.5">Открытые издания</p>
                    <p class="text-sm text-slate-300 leading-relaxed">Все публикации Института остаются открытыми.</p>
                </div>
                <div class="impact-tile rounded-xl border border-slate-700 bg-slate-900/60 p-4" style="top: 5.5rem">
                    <p class="font-bold text-white mb-0.5">Открытые занятия</p>
                    <p class="text-sm text-slate-300 leading-relaxed">Открытые разборы и бесплатные открытые занятия.</p>
                </div>
            </div>
        </article>

        {{-- Beat 3 — «Куда идут 500 ₽ в месяц»: ратифицированный состав
             меценатства (MG 08-09-2026), verbatim from the page H4400 block. --}}
        <article class="impact-beat rounded-xl border border-slate-700 p-5"
                 data-impact-beat="3" aria-labelledby="impact-b3">
            <p class="text-xs font-bold uppercase tracking-wide text-brand mb-1">Состав</p>
            <h3 id="impact-b3" class="text-lg font-bold text-white mb-3">Куда идут 500 ₽ в месяц</h3>
            <ul class="impact-list space-y-2 text-slate-300">
                <li class="impact-item rounded-lg border border-slate-700 bg-slate-900/60 p-3" style="top: 4rem">ежемесячный научный разбор;</li>
                <li class="impact-item rounded-lg border border-slate-700 bg-slate-900/60 p-3" style="top: 4.75rem">ранний доступ к новым публикациям и изданиям;</li>
                <li class="impact-item rounded-lg border border-slate-700 bg-slate-900/60 p-3" style="top: 5.5rem">благодарности меценатам в изданиях (по согласию);</li>
                <li class="impact-item rounded-lg border border-slate-700 bg-slate-900/60 p-3" style="top: 6.25rem">приоритет на очных встречах Института (1–2 встречи в год).</li>
            </ul>
        </article>

        {{-- Beat 4 — «Кто уже с нами»: names only, never amounts; consent
             respected via publicList. Empty state is honest and open. --}}
        <article class="impact-beat rounded-xl border border-slate-700 p-5"
                 data-impact-beat="4" aria-labelledby="impact-b4">
            <p class="text-xs font-bold uppercase tracking-wide text-brand mb-1">Меценаты</p>
            <h3 id="impact-b4" class="text-lg font-bold text-white mb-3">Кто уже с нами</h3>
            @if($impactGratitudes->isNotEmpty())
                <p class="text-sm text-slate-400 mb-2">
                    {{ $impactGratitudes->count() }} {{ trans_choice('меценат|мецената|меценатов', $impactGratitudes->count()) }} в открытом списке:
                </p>
                <ul class="space-y-1 text-slate-200">
                    @foreach($impactGratitudes as $gratitude)
                        <li>{{ $gratitude->name_display }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-slate-300">Список открыт.</p>
            @endif
            <p class="text-sm text-slate-400 mt-3">
                Имена публикуются только с согласия каждого мецената; суммы не публикуются.
            </p>
        </article>

        {{-- Beat 5 — CTA: anchor to the existing form; legal note (ст. 582)
             stays in its own block on the page, untouched. --}}
        <article class="impact-beat rounded-xl border border-brand p-5 text-center"
                 data-impact-beat="5" aria-labelledby="impact-b5">
            <h3 id="impact-b5" class="text-lg font-bold text-white mb-3">Стать меценатом</h3>
            <p class="text-slate-300 mb-4">
                Пожертвование — добровольное, по донорской рамке
                ст. 582 ГК (правовая основа — ниже на странице).
            </p>
            <a href="#mecenaty-form"
               class="inline-block rounded-lg bg-brand px-6 py-2 font-bold text-white hover:opacity-90">
                Поддержать
            </a>
        </article>
    </div>
</section>

@push('head')
<style>
    /* H4522 — reveal is opt-in: without JS (`.impact-js` added by the script
       below) the beats are plain sections, same text and order, no reveal.
       CSS `sticky` is layout and still applies; prefers-reduced-motion turns
       stickiness and transitions off. */
    .impact-beat { transition: opacity .45s ease, transform .45s ease; }
    .impact-js .impact-beat:not(.is-in) { opacity: 0; transform: translateY(14px); }
    .impact-tile, .impact-item { position: sticky; z-index: 1; }
    #mecenaty-form { scroll-margin-top: 5rem; }

    @media (prefers-reduced-motion: reduce) {
        .impact-beat,
        .impact-js .impact-beat:not(.is-in) { transition: none; opacity: 1; transform: none; }
        .impact-tile, .impact-item { position: static; }
    }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        var root = document.getElementById('impact-scroll');
        if (!root) return;

        document.documentElement.classList.add('impact-js');

        // Same shop Metrika helper as the marathon scrolly (shop-metrika partial).
        window.reachGoal = window.reachGoal || window.shopReachGoal || function () {};

        var beats = root.querySelectorAll('[data-impact-beat]');
        var seen = {};

        function reveal(el) {
            el.classList.add('is-in');
            var step = el.getAttribute('data-impact-beat');
            if (step && step !== '5' && !seen[step]) {
                seen[step] = true;
                try { window.reachGoal('scrolly_impact_' + step); } catch (e) { /* never break the page */ }
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
