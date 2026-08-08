{{--
  Site-wide Yandex Metrika for the shop layout (H2378).

  Root cause of H2062 zero card/checkout goals: layouts/shop had no counter,
  so URL goals never saw /k/* or /checkout/* pageviews. Promo landings had
  per-page IDs; payment success only fired when session carried yandex_id.

  Counter id: config('analytics.metrika.shop_counter_id') default 106964341
  (samskrte.ru). No PII in reachGoal payloads — goal name only.
--}}
@php
    $metrikaEnabled = (bool) config('analytics.metrika.enabled', true);
    $metrikaId = config('analytics.metrika.shop_counter_id');
@endphp
@if($metrikaEnabled && $metrikaId)
<script type="text/javascript">
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym({{ $metrikaId }}, "init", {
        clickmap:true,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor:true
   });

   window.SHOP_METRIKA_ID = {{ $metrikaId }};
   window.shopReachGoal = function (goalName) {
        if (!goalName || typeof ym === 'undefined') return;
        try { ym(window.SHOP_METRIKA_ID, 'reachGoal', goalName); } catch (e) { /* never break shop */ }
   };
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{{ $metrikaId }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
@endif
