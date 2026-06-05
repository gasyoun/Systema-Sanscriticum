{{-- Глобальная модалка «Купить пробное занятие».
     Подключается один раз: @include('partials.trial-modal').
     Триггер — window-событие `open-trial-modal` с detail { action, title, amount }. --}}
<div x-data="{
        open: false,
        action: '',
        courseTitle: '',
        amount: 0,
        get amountFormatted() {
            return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(this.amount);
        }
     }"
     x-show="open"
     x-cloak
     x-on:open-trial-modal.window="
        action = $event.detail.action;
        courseTitle = $event.detail.title;
        amount = Number($event.detail.amount) || 0;
        open = true;
     "
     x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
     x-on:click.self="open = false"
     style="display: none;">

    <div class="bg-[#111622] text-white rounded-2xl shadow-2xl w-full max-w-md border border-[#1F2636] overflow-hidden"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        <div class="flex items-center justify-between px-6 py-4 border-b border-[#1F2636]">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-graduation-cap text-[#38BDF8]"></i>
                Пробное занятие
            </h3>
            <button type="button" x-on:click="open = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-[#1F2636] transition-colors">
                <i class="fas fa-times text-slate-400"></i>
            </button>
        </div>

        <form :action="action" method="POST" class="p-6 space-y-4">
            @csrf

            <p class="text-sm text-slate-400 leading-relaxed">
                Пробное занятие курса <span class="font-bold text-white" x-text="courseTitle"></span>.
                Сумма <span class="font-bold text-[#38BDF8]"><span x-text="amountFormatted"></span> ₽</span>
                будет зачтена в стоимость тарифа при последующей оплате.
            </p>

            <div class="rounded-xl border border-[#38BDF8]/30 bg-[#38BDF8]/10 p-3.5">
                <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                    <i class="fas fa-door-open mr-1"></i> Доступ сразу после оплаты
                </div>
                <p class="text-[13px] text-slate-300 leading-relaxed">
                    Откроется <span class="font-semibold text-white">одно занятие</span> курса —
                    посмотрите запись и оцените формат, прежде чем покупать целиком.
                </p>
            </div>

            @guest
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Имя</label>
                    <input type="text" name="name" required maxlength="255"
                           placeholder="Как к вам обращаться"
                           class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Email</label>
                    <input type="email" name="email" required maxlength="255"
                           placeholder="you@example.com"
                           class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                </div>

                <p class="text-[11px] text-slate-500 leading-relaxed">
                    После оплаты пришлём пароль на email — войдёте в личный кабинет и откроете пробное занятие.
                </p>
            @endguest

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 bg-[#38BDF8] hover:bg-[#2da4dd] text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-[#38BDF8]/20">
                <i class="fas fa-credit-card mr-2"></i>
                Оплатить <span class="ml-1" x-text="amountFormatted"></span>&nbsp;₽
            </button>
        </form>
    </div>
</div>
