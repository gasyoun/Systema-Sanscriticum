{{--
    Уведомление о cookie. Alpine + localStorage: показывается до первого «Принять все».
    Самодостаточный [x-cloak] — на случай, если в layout'е его нет (как в main.blade.php).
--}}
<style>[x-cloak]{display:none!important}</style>
<div x-data="{ open: false }"
     x-init="open = ! localStorage.getItem('cookie_consent_v1')"
     x-show="open" x-cloak x-transition.opacity
     class="fixed inset-x-0 bottom-0 z-[200] bg-[#0A0D14]/95 backdrop-blur-sm text-slate-200 border-t border-[#1F2636] px-4 py-3 shadow-[0_-4px_20px_rgba(0,0,0,0.3)]">
    <div class="container mx-auto flex flex-col sm:flex-row items-center gap-3 text-sm">
        <p class="flex-1 text-center sm:text-left leading-relaxed">
            Наш сайт использует файлы cookie. Продолжая им пользоваться, вы соглашаетесь на
            обработку персональных данных в соответствии с
            <a href="{{ route('docs.show', 'privacy') }}"
               class="text-[#E85C24] underline hover:no-underline">политикой конфиденциальности</a>.
        </p>
        <button type="button"
                @click="localStorage.setItem('cookie_consent_v1','1'); open = false"
                class="shrink-0 px-5 py-2 rounded-lg bg-[#E85C24] text-white font-semibold hover:bg-[#d14e1a] transition-colors">
            Принять все
        </button>
    </div>
</div>
