<div class="font-nunito max-w-7xl mx-auto px-4 py-8">
    
    {{-- Заголовок --}}
    <div class="mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-3 tracking-tight">Мои оплаты</h1>
        <p class="text-gray-500 text-lg">Здесь отображается история ваших платежей и доступов к курсам.</p>
        <div class="w-16 h-1.5 bg-[#E85C24] rounded-full mt-4"></div>
    </div>

    {{-- Блок с таблицей --}}
    <div class="bg-white border border-gray-100 rounded-[1.5rem] shadow-sm overflow-hidden">
        
        @if($payments->count() > 0)
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50/50 border-b border-gray-100 text-gray-400 text-xs uppercase tracking-widest">
                            <th class="px-6 py-4 font-bold">Дата и время</th>
                            <th class="px-6 py-4 font-bold">Курс и Тариф</th>
                            <th class="px-6 py-4 font-bold">Сумма</th>
                            <th class="px-6 py-4 font-bold">Статус</th>
                            <th class="px-6 py-4 font-bold text-right">Транзакция</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($payments as $payment)
                            <tr class="hover:bg-orange-50/30 transition-colors group">
                                
                                {{-- Дата --}}
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="text-gray-900 font-bold">{{ $payment->created_at->translatedFormat('d F Y') }}</div>
                                    <div class="text-gray-400 text-xs mt-0.5">{{ $payment->created_at->format('H:i') }}</div>
                                </td>

                                {{-- Описание (Название КУРСА и тариф) --}}
                                <td class="px-6 py-5">
                                    <div class="text-gray-800 font-semibold group-hover:text-[#E85C24] transition-colors line-clamp-2">
                                        {{ $payment->course->title ?? 'Курс удален или не найден' }}
                                    </div>
                                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mt-1">
                                        Тариф: {{ $payment->tariff }}
                                    </div>
                                </td>

                                {{-- Сумма --}}
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="text-lg font-extrabold text-gray-900">
                                        {{ number_format($payment->amount, 0, '.', ' ') }} ₽
                                    </div>
                                </td>

                                {{-- Статус (Под ваши статусы: paid, pending, canceled) --}}
                                <td class="px-6 py-5 whitespace-nowrap">
                                    @if($payment->status === 'paid')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-50 text-green-600 border border-green-200">
                                            <div class="w-1.5 h-1.5 rounded-full bg-green-500 mr-2 animate-pulse"></div>
                                            Оплачено
                                        </span>
                                    @elseif($payment->status === 'pending')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-yellow-50 text-yellow-600 border border-yellow-200">
                                            <div class="w-1.5 h-1.5 rounded-full bg-yellow-500 mr-2"></div>
                                            В обработке
                                        </span>
                                    @elseif($payment->status === 'canceled')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-600 border border-red-200">
                                            <div class="w-1.5 h-1.5 rounded-full bg-red-500 mr-2"></div>
                                            Отменено
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-gray-50 text-gray-600 border border-gray-200">
                                            {{ $payment->status }}
                                        </span>
                                    @endif
                                </td>

                                {{-- Номер транзакции --}}
                                <td class="px-6 py-5 whitespace-nowrap text-right">
                                    <div class="text-xs text-gray-400 font-mono">
                                        {{ $payment->transaction_id ?? '—' }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            
            {{-- Пагинация --}}
            @if($payments->hasPages())
                <div class="p-6 border-t border-gray-100 bg-gray-50/30">
                    {{ $payments->links() }}
                </div>
            @endif

        @else
            {{-- Если оплат нет --}}
            <div class="text-center py-20">
                <div class="w-20 h-20 mx-auto bg-gray-50 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">История оплат пуста</h3>
                <p class="text-gray-500">У вас пока нет ни одного совершенного платежа.</p>
            </div>
        @endif

    </div>
</div>