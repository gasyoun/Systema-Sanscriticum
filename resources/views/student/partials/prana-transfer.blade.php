{{-- P2P: подарить прану другому студенту. --}}
<div class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 md:p-6 shadow-sm">
    <div class="flex items-start gap-3 mb-4">
        <div class="w-10 h-10 rounded-xl bg-brand/10 text-brand flex items-center justify-center shrink-0">
            <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div>
            <h3 class="text-lg font-extrabold text-[#101010]">Подарить прану</h3>
            <p class="text-gray-500 text-sm">Переведите прану другому студенту по его email. На ваш ранг это не влияет.</p>
        </div>
    </div>

    @if (session('prana_status'))
        <div class="mb-3 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-2.5">
            <i class="fas fa-check-circle mr-1.5"></i>{{ session('prana_status') }}
        </div>
    @endif
    @if (session('prana_error'))
        <div class="mb-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-2.5">
            <i class="fas fa-triangle-exclamation mr-1.5"></i>{{ session('prana_error') }}
        </div>
    @endif

    <form action="{{ route('student.prana.transfer') }}" method="POST" class="flex flex-col sm:flex-row gap-2">
        @csrf
        <input type="email" name="email" required placeholder="email студента"
               class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition">
        <input type="number" name="amount" required min="1" placeholder="сколько праны"
               class="sm:w-40 px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition">
        <button type="submit"
                class="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-colors">
            <i class="fas fa-paper-plane"></i> Отправить
        </button>
    </form>
    <p class="text-[11px] text-gray-400 mt-2">
        Лимиты: до {{ (int) config('prana.daily_p2p_limit', 30) }} праны в день всего и до
        {{ (int) config('prana.daily_p2p_per_user_limit', 10) }} одному студенту.
    </p>
</div>
