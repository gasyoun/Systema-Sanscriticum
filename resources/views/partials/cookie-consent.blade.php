{{--
    Уведомление о cookie. Alpine + localStorage: показывается до первого «Принять все».
    Самодостаточный [x-cloak] — на случай, если в layout'е его нет (как в main.blade.php).
--}}
<style>[x-cloak]{display:none!important}</style>
{{--
    Пока баннер открыт, он резервирует под себя место снизу (padding-bottom у body).
    Он `fixed bottom-0`, а на телефоне разворачивается в колонку и занимает ~164px —
    почти четверть экрана 375x667. Без компенсации эта полоса просто накрывала
    нижний контент (на чекауте — зону кнопки оплаты и итоговой суммы).
--}}
<div x-data="{ open: false }"
     x-init="open = ! localStorage.getItem('cookie_consent_v1')"
     x-effect="$nextTick(() => document.body.style.paddingBottom = open ? $el.offsetHeight + 'px' : '')"
     x-show="open" x-cloak x-transition.opacity
     class="fixed inset-x-0 bottom-0 z-[10050] bg-[#0A0D14]/95 backdrop-blur-sm text-slate-200 border-t border-[#1F2636] px-4 py-3 shadow-[0_-4px_20px_rgba(0,0,0,0.3)]">
    <div class="container mx-auto flex flex-col sm:flex-row items-center gap-3 text-sm">
        <p class="flex-1 text-center sm:text-left leading-relaxed">
            Наш сайт использует файлы cookie. Продолжая им пользоваться, вы соглашаетесь на
            обработку персональных данных в соответствии с
            <a href="{{ route('docs.show', 'privacy') }}"
               class="text-brand underline hover:no-underline">политикой конфиденциальности</a>.
        </p>
        <button type="button"
                @click="localStorage.setItem('cookie_consent_v1','1'); open = false"
                class="shrink-0 px-5 py-2 rounded-lg bg-brand text-white font-semibold hover:bg-brand-hover transition-colors">
            Принять все
        </button>
    </div>
</div>
