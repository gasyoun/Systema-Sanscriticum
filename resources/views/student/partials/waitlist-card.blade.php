{{-- Список ожидания: голосование за будущие группы (H3815, MG 31-08-2026).
     Рендерится только когда $waitlistItems непустой (флаг waitlist_voting ON). --}}
@if (isset($waitlistItems) && $waitlistItems->isNotEmpty())
    <div class="mb-8" x-data="waitlistVote()">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-extrabold text-gray-900 uppercase tracking-wider">
                <i class="fas fa-hand-raised mr-2 text-brand"></i>Список ожидания
            </h2>
            <p class="text-xs text-gray-400">Голосов наберётся минимум — откроется оплата; оплаты к сроку — группа стартует</p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($waitlistItems as $item)
                @php
                    $already = (int) $item->voted_by_me > 0;
                    $met = (int) $item->votes_count >= $item->min_payers;
                    $earliest = $item->earliest_start_at?->format('d.m.Y');
                @endphp
                <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-col gap-2"
                     data-waitlist-row="{{ $item->slug }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-bold text-gray-900 text-sm leading-snug">{{ $item->course_title }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $item->teacher_name }}
                                @if ($item->slot) · {{ $item->slot }} @endif
                                @if ($earliest)
                                    · не раньше {{ $earliest }}
                                @endif
                            </p>
                        </div>
                        @if ($item->block_price_rub)
                            <span class="text-xs font-bold text-gray-700 whitespace-nowrap bg-gray-100 rounded-lg px-2 py-1">
                                {{ number_format($item->block_price_rub, 0, ',', ' ') }} ₽
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center justify-between mt-1">
                        <span class="text-xs font-semibold {{ $met ? 'text-green-600' : 'text-gray-500' }}"
                              data-waitlist-progress="{{ $item->slug }}">
                            Голосов: <span data-waitlist-count>{{ $item->votes_count }}</span> из {{ $item->min_payers }}
                            @if ($met) <i class="fas fa-check-circle ml-1"></i> @endif
                        </span>

                        @if ($already)
                            <span class="text-xs font-bold text-green-600"><i class="fas fa-check mr-1"></i>Голос учтён</span>
                        @elseif ($item->status === \App\Models\CourseWaitlistItem::STATUS_PAYMENT_OPEN)
                            <span class="text-xs font-bold text-green-700 bg-green-50 px-2 py-1 rounded-lg">Оплата открыта — ждём администратора</span>
                        @else
                            <button type="button"
                                    data-waitlist-vote="{{ $item->slug }}"
                                    class="text-xs font-bold text-white bg-brand hover:opacity-90 transition rounded-lg px-3 py-1.5"
                                    x-on:click="vote('{{ $item->slug }}', $el)">
                                Голосовать
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <script>
        function waitlistVote() {
            return {
                async vote(slug, el) {
                    el.disabled = true;
                    el.textContent = '…';
                    try {
                        const resp = await fetch('/api/public/waitlist/vote', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ slug }),
                        });
                        if (resp.status === 401) { window.location.href = '/login'; return; }
                        const data = await resp.json();
                        const row = el.closest('[data-waitlist-row]');
                        if (data.ok) {
                            const count = row?.querySelector('[data-waitlist-count]');
                            if (count) count.textContent = data.votes;
                            el.outerHTML = '<span class="text-xs font-bold text-green-600"><i class="fas fa-check mr-1"></i>Голос учтён</span>';
                        } else {
                            el.textContent = 'Не вышло';
                        }
                    } catch (e) {
                        el.textContent = 'Ошибка сети';
                    }
                },
            };
        }
        </script>
    </div>
@endif