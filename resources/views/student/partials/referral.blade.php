{{-- Реферальная программа: ссылка-приглашение + статистика. --}}
@php
    $me = auth()->user();
    $referralLink = $me->referralLink();
    $invited = $me->referrals()->count();
    $reward = (int) config('referral.credit_amount', 500);
    $credit = (float) ($me->referral_credit ?? 0);
@endphp

<div class="mb-6 rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 to-white p-5 md:p-6 shadow-sm"
     x-data="{ copied: false }">
    <div class="flex items-start gap-3 mb-4">
        <div class="w-10 h-10 rounded-xl bg-[#E85C24]/10 text-[#E85C24] flex items-center justify-center shrink-0">
            <i class="fas fa-gift"></i>
        </div>
        <div>
            <h3 class="text-lg font-extrabold text-[#101010]">Приглашайте друзей</h3>
            <p class="text-gray-500 text-sm">
                Поделитесь ссылкой. Когда друг оплатит курс, вам начислят
                <span class="font-bold text-[#E85C24]">{{ number_format($reward, 0, '.', ' ') }} ₽</span>
                на счет — они автоматически спишутся в счет вашей следующей покупки.
            </p>
        </div>
    </div>

    @if($credit > 0)
        <div class="mb-4 flex items-center justify-between gap-3 rounded-xl bg-white border border-emerald-200 px-4 py-3">
            <span class="text-sm text-gray-600">Ваш реферальный кредит</span>
            <span class="text-lg font-extrabold text-emerald-600 tabular-nums">{{ number_format($credit, 0, '.', ' ') }} ₽</span>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row gap-2">
        <input type="text" readonly value="{{ $referralLink }}"
               x-ref="link"
               class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-mono outline-none">
        <button type="button"
                x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
                class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-[#E85C24] hover:bg-[#d04a15] text-white text-sm font-bold transition-colors">
            <i class="fas" :class="copied ? 'fa-check' : 'fa-copy'"></i>
            <span x-text="copied ? 'Скопировано' : 'Скопировать'"></span>
        </button>
    </div>

    <div class="mt-3 text-sm text-gray-500">
        Приглашено друзей: <span class="font-bold text-gray-800">{{ $invited }}</span>
    </div>
</div>
