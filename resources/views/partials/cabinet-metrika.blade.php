{{--
  Кабинетная Яндекс.Метрика (рулинг MG 18-09-2026, план
  docs/METRIKA_GOALS_SHOP_CABINET_2026-09-18.md §Implementation 1).

  ТОТ ЖЕ счетчик, что и в магазине (106964341): воронка course_page_view →
  begin_checkout → payment_success → кабинетные цели живет в одном отчете.

  Железное условие рулинга: внутри залогиненного кабинета НЕТ ни webvisor,
  ни clickmap — ни одной записи сессий залогиненных (152-ФЗ). First-party
  truth остается в activity_events (H2378), Метрика — браузерный прокси
  для воронки Директа. Никакого PII: только имена целей, без userParams
  и setUserID.

  Мост серверных событий: страница несет элементы data-metrika-goal
  (условные маркеры рендерит сам сервер), лоадер ниже стреляет reachGoal
  по каждому. Клиентские события кабинета дублирует в reachGoal
  student/partials/telemetry.blade.php (карта METRIKA_BRIDGE).
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
        clickmap:false,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor:false
   });

   window.SHOP_METRIKA_ID = {{ $metrikaId }};
   window.shopReachGoal = window.shopReachGoal || function (goalName) {
        if (!goalName || typeof ym === 'undefined') return;
        try { ym(window.SHOP_METRIKA_ID, 'reachGoal', goalName); } catch (e) { /* никогда не ломаем кабинет */ }
   };

   // Маркеры серверных событий: [data-metrika-goal] → reachGoal один раз на загрузке.
   function __cabinetMetrikaFireMarkers() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-metrika-goal]'),
            function (el) { window.shopReachGoal(el.getAttribute('data-metrika-goal')); }
        );
   }
   if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', __cabinetMetrikaFireMarkers);
   } else {
        __cabinetMetrikaFireMarkers();
   }
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{{ $metrikaId }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
@endif
{{-- Flash-маркер (например, lesson_mark_mastered после completeLesson):
     контроллер делает session()->flash('metrika_goal', <цель>) — цель
     стреляет ровно один раз, на следующей загрузке. --}}
@if(session('metrika_goal'))
<span data-metrika-goal="{{ session('metrika_goal') }}" hidden></span>
@endif
