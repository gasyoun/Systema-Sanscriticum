{{-- Приветствие приглашенного (H1294): рендерится на главной, когда в сессии
     лежит валидный реферальный код (?ref=CODE → CaptureReferral). Имя
     пригласившего подтверждает, что ссылка действительно от знакомого, —
     иначе заход по личной рекомендации неотличим от рекламного. Невалидный
     код — ни баннера, ни ошибки. Строки: docs/copy/money-referral-invite-ask.md. --}}
@php
    $referrerName = session()->has('ref')
        ? \App\Models\User::where('referral_code', session('ref'))->value('name')
        : null;
@endphp

@if(filled($referrerName))
    <div class="max-w-2xl mx-auto mb-10 rounded-2xl border border-white/10 bg-white/5 px-6 py-4 text-center">
        <p class="text-gray-300 leading-relaxed">
            {{ $referrerName }} рекомендует вам нашу школу.
            Осмотритесь: начать можно с бесплатных открытых занятий.
        </p>
    </div>
@endif
