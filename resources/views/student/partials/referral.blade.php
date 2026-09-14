{{-- Рекомендация школы (H1294): личная ссылка-приглашение + объяснение кредита.
     Рамка — рекомендация преподавателя человеку, которому это нужно, а не
     заработок: без геймификации, кредит — благодарность, а не вознаграждение.
     Все строки и решения: docs/copy/money-referral-invite-ask.md. --}}
@php
    $me = auth()->user();
    $referralLink = $me->referralLink();
    $invited = $me->referrals()->count();
    $reward = (int) config('referral.credit_amount', 500);
    $credit = (float) ($me->referral_credit ?? 0);
    $shareText = 'Я занимаюсь санскритом в Обществе ревнителей санскрита — шаг за шагом, с преподавателем, в маленькой группе. Если присматриваешься к санскриту — начни с бесплатных открытых занятий: '.$referralLink;
@endphp

<div id="referral" class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 md:p-6 shadow-sm"
     x-data="{ copiedLink: false, copiedText: false }">
    <h3 class="text-lg font-extrabold text-[#101010] mb-2">Порекомендовать школу</h3>
    <p class="text-gray-500 text-sm leading-relaxed mb-4">
        Если среди ваших знакомых есть человек, которому санскрит был бы в радость, —
        поделитесь личной ссылкой. Для нас рекомендация ученика значит больше любой
        рекламы.
    </p>

    <div class="flex flex-col sm:flex-row gap-2 mb-5">
        <input type="text" readonly value="{{ $referralLink }}"
               x-ref="link"
               class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-700 text-sm font-mono outline-none">
        <button type="button"
                x-on:click="navigator.clipboard.writeText($refs.link.value); copiedLink = true; setTimeout(() => copiedLink = false, 2000)"
                class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-colors">
            <i class="fas" :class="copiedLink ? 'fa-check' : 'fa-copy'"></i>
            <span x-text="copiedLink ? 'Скопировано' : 'Скопировать'"></span>
        </button>
    </div>

    <div class="mb-5">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">
            Если не знаете, с чего начать сообщение
        </p>
        <div class="rounded-xl bg-gray-50 border border-gray-100 px-4 py-3 text-sm text-gray-600 leading-relaxed"
             x-ref="shareText">{{ $shareText }}</div>
        <button type="button"
                x-on:click="navigator.clipboard.writeText($refs.shareText.textContent.trim()); copiedText = true; setTimeout(() => copiedText = false, 2000)"
                class="mt-2 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition-colors">
            <i class="fas" :class="copiedText ? 'fa-check' : 'fa-copy'"></i>
            <span x-text="copiedText ? 'Скопировано' : 'Скопировать текст'"></span>
        </button>
    </div>

    <p class="text-sm text-gray-500 leading-relaxed">
        Когда приглашенный вами человек впервые оплатит курс, мы зачислим вам
        {{ number_format($reward, 0, '.', ' ') }} ₽ — в знак благодарности. Сумма
        зачтется автоматически при вашей следующей покупке: делать ничего не нужно.
    </p>

    @if($credit > 0)
        <div class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-gray-50 border border-gray-100 px-4 py-3">
            <span class="text-sm text-gray-600">Начислено за рекомендации</span>
            <span class="text-base font-extrabold text-gray-900 tabular-nums">{{ number_format($credit, 0, '.', ' ') }} ₽</span>
        </div>
    @endif

    @if($invited > 0)
        <p class="mt-3 text-sm text-gray-400">Пришли по вашей рекомендации: {{ $invited }}</p>
    @endif
</div>
