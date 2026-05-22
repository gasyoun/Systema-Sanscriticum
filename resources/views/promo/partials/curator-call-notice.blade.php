{{-- Предупреждение о звонке куратора с белорусского номера. --}}
{{-- Показывается на странице "Спасибо" (promo/thankyou.blade.php) в обеих ветках. --}}
<div class="mt-8 mx-auto max-w-xl">
    <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 text-left
                bg-white/5 border border-white/10 rounded-2xl px-5 py-4
                shadow-[0_0_25px_-10px_rgba(255,255,255,0.15)]">
        <div class="shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500/30 to-emerald-700/20
                    border border-emerald-400/30 flex items-center justify-center
                    shadow-[0_0_15px_-3px_rgba(16,185,129,0.4)]">
            <svg class="w-6 h-6 text-emerald-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 5a2 2 0 0 1 2-2h2.28a1 1 0 0 1 .95.68l1.5 4.49a1 1 0 0 1-.5 1.21l-1.86.94a11 11 0 0 0 5.36 5.36l.94-1.86a1 1 0 0 1 1.21-.5l4.49 1.5a1 1 0 0 1 .68.95V19a2 2 0 0 1-2 2h-1C9.72 21 3 14.28 3 6V5z"/>
            </svg>
        </div>
        <div class="flex-1 text-center sm:text-left">
            <p class="text-base font-semibold text-white mb-1">
                В течение 24 часов с вами свяжется наш куратор Настя
            </p>
            <p class="text-sm text-gray-300 leading-relaxed">
                Ожидайте звонок с номера
                <a href="tel:+375295412119"
                   class="font-mono font-bold text-emerald-300 hover:text-emerald-200 whitespace-nowrap">
                    +3(7529)&#8209;541&#8209;21&#8209;19
                </a>
                (Беларусь).
            </p>
            <p class="text-sm text-gray-300 leading-relaxed mt-2">
                Если вдруг не получится ответить, просто перезвоните нам по тому же номеру&nbsp;— и мы обсудим все возникшие вопросы.
            </p>
        </div>
    </div>
</div>
