{{-- H5024 (MG Q12, 16-09-2026): личная ссылка-приглашение владельца сертификата
     под самим сертификатом. Гейт — config('partner.enabled') (вкл на проде с
     24-08-2026); при OFF блок не рендерится байт-в-байт как раньше. Голос H1294:
     рекомендация, а не заработок — без счётчиков, без срочности. Копия без ё.
     Кнопка «Скопировать» переиспользует обработчик [data-copy-url] из
     certificate.partials.share — этот партиал должен стоять ВЫШЕ share в разметке. --}}
@props(['user' => null])

@php
    $referralLink = $user?->referralLink();
    $referralCredit = (int) config('referral.credit_amount', 500);
@endphp

@if ($referralLink)
<div class="mt-5 bg-[#111622] border border-[#1F2636] rounded-3xl p-6 md:p-8" data-testid="certificate-referral-invite">
    <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-3">Пригласить в школу</div>
    <p class="text-sm text-slate-400 leading-relaxed mb-4">
        Если среди знакомых владельца сертификата есть человек, которому санскрит был бы
        в радость, — вот личная ссылка-приглашение. Новый ученик переходит по ней и
        начинает с бесплатных открытых занятий.
    </p>
    <div class="flex flex-col sm:flex-row gap-2">
        <input type="text" readonly value="{{ $referralLink }}"
               class="flex-1 px-4 py-2 rounded-xl bg-[#0B0F19] border border-[#2A3348] text-slate-200 text-sm font-mono outline-none">
        <button type="button"
                data-copy-url="{{ $referralLink }}"
                class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-[#1F2636] border border-[#2A3348] text-sm font-semibold text-slate-200 hover:border-brand hover:text-white transition-colors">
            <i class="fas fa-link"></i> <span class="js-copy-label">Скопировать ссылку</span>
        </button>
    </div>
    <p class="mt-4 text-xs text-slate-500 leading-relaxed">
        Когда приглашенный по этой ссылке человек впервые оплатит курс,
        владельцу сертификата зачислится {{ number_format($referralCredit, 0, '.', ' ') }} ₽ в знак благодарности —
        сумма зачтется при его следующей покупке.
    </p>
</div>
@endif
