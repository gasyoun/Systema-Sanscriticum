{{--
  Пиксель VK Ads (Top.Mail.Ru) на всю витрину — пара к partials/shop-metrika.

  Раньше пиксель ВК стоял только на промо-лендингах и в блоге, поэтому
  реклама, ведущая прямо на /k/{slug}, не видела ни просмотров, ни чекаута,
  ни оплат. ID — config('analytics.vk_pixel.shop_pixel_id'), пусто = выкл.

  Подключается ПОСЛЕ shop-metrika: оборачивает window.shopReachGoal, чтобы
  каждая цель витрины (course_page_view, begin_checkout, sample_play, …)
  уходила и в Метрику, и в ВК. Без ПДн — только имя цели.
--}}
@php
    $shopVkPixelId = \App\Support\ShopVkPixel::id();
@endphp
@if($shopVkPixelId)
{{-- 152-ФЗ: пиксель — только после «Принять аналитику» (partials/analytics-gate).
     Цели до согласия копятся в window._tmr и никуда не уходят (скрипта нет). --}}
@include('partials.analytics-gate')
<script type="text/javascript">
   ssConsent.onAnalytics(function () {
   var _tmr = window._tmr || (window._tmr = []);
   _tmr.push({id: "{{ $shopVkPixelId }}", type: "pageView", start: (new Date()).getTime()});
   (function (d, w, id) {
       if (d.getElementById(id)) return;
       var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
       ts.src = "https://top-fwz1.mail.ru/js/code.js";
       var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
       if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
   })(document, window, "tmr-code");
   });

   window.SHOP_VK_PIXEL_ID = "{{ $shopVkPixelId }}";
   (function () {
       var metrikaReachGoal = window.shopReachGoal;
       window.shopReachGoal = function (goalName) {
           if (typeof metrikaReachGoal === 'function') metrikaReachGoal(goalName);
           if (!goalName) return;
           try {
               (window._tmr || (window._tmr = [])).push({ type: 'reachGoal', id: window.SHOP_VK_PIXEL_ID, goal: goalName });
           } catch (e) { /* never break shop */ }
       };
   })();
</script>
@endif
