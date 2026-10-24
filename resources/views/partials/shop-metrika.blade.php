{{--
  Site-wide Yandex Metrika for the shop layout (H2378).

  Root cause of H2062 zero card/checkout goals: layouts/shop had no counter,
  so URL goals never saw /k/* or /checkout/* pageviews. Promo landings had
  per-page IDs; payment success only fired when session carried yandex_id.

  Counter id: config('analytics.metrika.shop_counter_id') default 106964341
  (samskrte.ru). No PII in reachGoal payloads — goal name only.

  MG 18-09-2026 (152-ФЗ): webvisor writes GUESTS ONLY — a logged-in student
  browsing /k/*, /checkout/* or /online must never be recorded, so init
  sends webvisor:false for any authenticated session (plan
  docs/METRIKA_GOALS_SHOP_CABINET_2026-09-18.md §Architecture 3).
--}}
@php
    $metrikaEnabled = (bool) config('analytics.metrika.enabled', true);
    $metrikaId = config('analytics.metrika.shop_counter_id');
@endphp
@if($metrikaEnabled && $metrikaId)
{{-- 152-ФЗ: счётчик — только после «Принять аналитику» (partials/analytics-gate);
     вебвизор гостя не пишет ввод в поля (ym-disable-keys ставит шлюз). --}}
@include('partials.analytics-gate')
<script type="text/javascript">
   ssConsent.onAnalytics(function () {
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym({{ $metrikaId }}, "init", {
        clickmap:true,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor: {{ auth()->check() ? 'false' : 'true' }}
   });
   });

   window.SHOP_METRIKA_ID = {{ $metrikaId }};
   window.shopReachGoal = function (goalName) {
        if (!goalName || typeof ym === 'undefined') return;
        try { ym(window.SHOP_METRIKA_ID, 'reachGoal', goalName); } catch (e) { /* never break shop */ }
   };

   // Маркеры серверных событий (H6521): [data-metrika-goal] → reachGoal один раз на загрузке.
   function __shopMetrikaFireMarkers() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-metrika-goal]'),
            function (el) { window.shopReachGoal(el.getAttribute('data-metrika-goal')); }
        );
   }
   ssConsent.onAnalytics(function () {
       if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', __shopMetrikaFireMarkers);
       } else {
            __shopMetrikaFireMarkers();
       }
   });
</script>
@endif
{{-- Flash-маркер (H6521: ga_interstitial_view после /ga/-хопа, waitlist_join
     после голоса ждуна): контроллер делает session()->flash('metrika_goal', <цель>)
     — цель стреляет ровно один раз, на следующей загрузке. Тот же канал,
     что в cabinet-metrika.blade.php. --}}
@if(session('metrika_goal'))
<span data-metrika-goal="{{ session('metrika_goal') }}" hidden></span>
@endif
