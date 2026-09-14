{{-- CTA «Счёт для компании» на чекауте. Flag: billing.company_invoice.enabled. --}}
@if(config('billing.company_invoice.enabled'))
    <div class="mb-6 bg-white border border-gray-200 rounded-2xl p-4 sm:p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="text-sm text-gray-600 leading-relaxed">
                <span class="font-bold text-gray-900">Оплата от компании?</span>
                Выставим счет на юрлицо или ИП — оплатите по реквизитам, доступ откроем после сверки поступления, обычно в течение одного-двух рабочих дней.
            </div>
            <a href="{{ route('invoice.claim.show', $tariff) }}"
               class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 min-h-[44px] rounded-xl border border-emerald-200 text-emerald-800 font-bold text-sm hover:bg-emerald-50 transition">
                <i class="fas fa-file-invoice"></i> Счет для компании
            </a>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Безнал по счету — без автосписания: каждый перевод сверяем вручную.
        </p>
    </div>
@endif
