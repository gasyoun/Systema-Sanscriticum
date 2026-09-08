{{-- H4387: кнопка раскрытия прошедших занятий в блоке FullSchedulePost::html().
     Один делегированный слушатель на документ — работает для любого числа
     блоков на странице. Кнопка и скрытые span'ы рендерятся билдером. --}}
<script>
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        var btn = target.closest('.fs-toggle');
        if (!btn) {
            return;
        }
        var scope = btn.closest('.fs-body');
        if (!scope) {
            return;
        }
        var shown = scope.getAttribute('data-fs-past') === 'shown';
        var spans = scope.querySelectorAll('.fs-past');
        for (var i = 0; i < spans.length; i++) {
            spans[i].hidden = shown;
        }
        scope.setAttribute('data-fs-past', shown ? 'hidden' : 'shown');
        btn.setAttribute('aria-expanded', shown ? 'false' : 'true');
        btn.textContent = shown ? 'Показать прошедшие занятия' : 'Скрыть прошедшие занятия';
    });
})();
</script>
