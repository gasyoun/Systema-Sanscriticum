@php
    // Живой статус набора одной группы курса (H3327). Привязка — общий
    // резолвер App\Support\StatusBlock (его же используют гейт выдачи
    // binding-токена и матчинг гостей при рассылке, H3339).
    $group = \App\Support\StatusBlock::resolveGroup($data ?? null);
@endphp

@if($group)
    <section class="py-10 lg:py-12 bg-[#FFF7F0]" id="waitlist-status">
        <div class="container mx-auto px-4">

            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-[2rem] border border-orange-100 shadow-sm p-8 text-center">
                    <div class="inline-block px-5 py-1.5 rounded-full bg-gradient-to-r from-orange-50 to-red-50 border border-orange-100 mb-4">
                        <span class="text-[#E3122C] font-bold text-xs uppercase tracking-[0.2em]">Статус курса</span>
                    </div>

                    <p class="text-lg md:text-xl font-semibold text-gray-900 leading-relaxed">
                        {{ \App\Services\WaitlistNotifier::statusText($group) }}
                    </p>
                </div>

                <div class="mt-6 text-sm text-gray-500 leading-relaxed">
                    <p class="font-semibold text-gray-600 mb-1">Подписавшись на уведомления, вы будете получать только статусы этого курса:</p>
                    <ol class="list-decimal list-inside space-y-0.5">
                        @foreach(\App\Services\WaitlistNotifier::vocabulary() as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ol>
                    <p class="mt-2 text-gray-400">Рекламных рассылок школы в этой подписке нет.</p>
                </div>
            </div>

        </div>
    </section>
@endif
