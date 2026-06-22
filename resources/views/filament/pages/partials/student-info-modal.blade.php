@php
    /** @var array $info */
    $u = $info['user'];
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';

    $payStatus = function ($s) {
        return match ($s) {
            'paid', 'success' => ['Оплачено', '#16a34a', '#dcfce7'],
            'pending'         => ['Ожидает', '#b45309', '#fef3c7'],
            'canceled', 'cancelled' => ['Отменено', '#6b7280', '#f3f4f6'],
            default           => [$s, '#6b7280', '#f3f4f6'],
        };
    };
    $promiseStatus = function ($s) {
        return match ($s) {
            'active'    => ['Активно', '#b45309', '#fef3c7'],
            'fulfilled' => ['Выполнено', '#16a34a', '#dcfce7'],
            'expired'   => ['Просрочено', '#dc2626', '#fee2e2'],
            'cancelled' => ['Отменено', '#6b7280', '#f3f4f6'],
            default     => [$s, '#6b7280', '#f3f4f6'],
        };
    };
@endphp

{{-- Оверлей --}}
<div wire:click.self="closeStudentInfo"
     style="position: fixed; inset: 0; background: rgba(17,24,39,0.55); z-index: 50; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto;">
    <div style="background: #fff; width: 100%; max-width: 720px; border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); overflow: hidden;">

        {{-- Шапка --}}
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 18px 22px; border-bottom: 1px solid #e5e7eb;">
            <div>
                <div style="font-size: 18px; font-weight: 700; color: #111827;">{{ $u->name }}</div>
                <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                    @if($u->global_status)
                        <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: #e0f2fe; color: #0369a1;">{{ $u->global_status }}</span>
                    @endif
                    @if($u->isUnreliable())
                        <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: #fee2e2; color: #dc2626;" title="{{ $u->unreliable_reason }}">⚠ Неблагонадёжный</span>
                    @endif
                    @if($u->isOnline())
                        <span style="font-size: 11px; font-weight: 600; color: #16a34a;">● онлайн</span>
                    @endif
                </div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a href="{{ \App\Filament\Resources\UserResource::getUrl('view', ['record' => $u->id]) }}" target="_blank"
                   style="font-size: 12px; font-weight: 600; color: #2563eb; text-decoration: none; white-space: nowrap;">Полная карточка ↗</a>
                <button type="button" wire:click="closeStudentInfo"
                        style="background: #f3f4f6; border: none; width: 30px; height: 30px; border-radius: 8px; cursor: pointer; font-size: 16px; color: #6b7280;">✕</button>
            </div>
        </div>

        <div style="padding: 18px 22px; display: flex; flex-direction: column; gap: 20px;">

            {{-- Контакты --}}
            <div>
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #9ca3af; margin-bottom: 8px;">Контакты</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; font-size: 13px; color: #374151;">
                    <div>✉ {{ $u->email ?: '—' }}</div>
                    <div>📞 {{ $u->phone ?: '—' }}</div>
                    <div>{{ $u->telegram_id ? '✈ Telegram' : '' }} {{ $u->vk_id ? '💬 VK' : '' }}{{ ! $u->telegram_id && ! $u->vk_id ? 'Мессенджеры не привязаны' : '' }}</div>
                    <div>🗓 в системе с {{ $u->created_at?->format('d.m.Y') ?? '—' }}</div>
                    @if(\App\Support\RoleGate::adminOnly())
                        <div>🌿 {{ (int) $u->prana_balance }} праны</div>
                    @endif
                </div>
                @if($u->note)
                    <div style="margin-top: 8px; font-size: 12px; color: #6b7280; background: #f9fafb; border-radius: 8px; padding: 8px 10px;">📝 {{ \Illuminate\Support\Str::limit($u->note, 240) }}</div>
                @endif
            </div>

            {{-- Оплаты --}}
            <div>
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #9ca3af; margin-bottom: 8px;">
                    Оплаты
                    <span style="color: #6b7280; font-weight: 500;">· всего {{ $info['payments_count'] }} на {{ $money($info['payments_total']) }}</span>
                </div>
                @forelse($info['payments'] as $p)
                    @php [$st, $sc, $sbg] = $payStatus($p->status); @endphp
                    <div style="display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px;">
                        <div style="min-width: 0;">
                            <div style="color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $p->course?->title ?? '—' }}</div>
                            <div style="color: #9ca3af; font-size: 11px;">{{ $p->created_at?->format('d.m.Y') }} · {{ $p->operationLabel() }}</div>
                        </div>
                        <div style="text-align: right; white-space: nowrap;">
                            <span style="font-weight: 700; color: {{ (float) $p->amount < 0 ? '#dc2626' : '#111827' }};">{{ $money($p->amount) }}</span>
                            @if($p->hasDiscount())
                                <span style="font-size: 11px; color: #16a34a;">{{ $p->discountLabel() }}</span>
                            @endif
                            <div><span style="font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: {{ $sbg }}; color: {{ $sc }};">{{ $st }}</span></div>
                        </div>
                    </div>
                @empty
                    <div style="font-size: 13px; color: #9ca3af;">Оплат нет</div>
                @endforelse
            </div>

            {{-- Обещания и рассрочки --}}
            <div>
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #9ca3af; margin-bottom: 8px;">Обещания и рассрочки</div>
                @forelse($info['promises'] as $pr)
                    @php [$st, $sc, $sbg] = $promiseStatus($pr->status); @endphp
                    <div style="display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px;">
                        <div style="min-width: 0;">
                            <div style="color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ $pr->course?->title ?? '—' }}
                                @if($pr->isPartOfInstallment())
                                    <span style="font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 99px; background: #e0e7ff; color: #4338ca;">рассрочка</span>
                                @endif
                            </div>
                            <div style="color: #9ca3af; font-size: 11px;">обещал к {{ $pr->promised_at?->format('d.m.Y') ?? '—' }}</div>
                        </div>
                        <div style="text-align: right; white-space: nowrap;">
                            <span style="font-weight: 700; color: #111827;">{{ $pr->amount ? $money($pr->amount) : '—' }}</span>
                            <div><span style="font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: {{ $sbg }}; color: {{ $sc }};">{{ $st }}</span></div>
                        </div>
                    </div>
                @empty
                    <div style="font-size: 13px; color: #9ca3af;">Обещаний и рассрочек нет</div>
                @endforelse
            </div>

            {{-- Скидки по курсам --}}
            <div>
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #9ca3af; margin-bottom: 8px;">Индивидуальные скидки</div>
                @forelse($info['discounts'] as $d)
                    <div style="display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px;">
                        <div style="min-width: 0;">
                            <div style="color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $d->course?->title ?? '—' }}</div>
                            <div style="color: #9ca3af; font-size: 11px;">
                                {{ $d->block_number ? 'Блок №' . $d->block_number : 'Все блоки' }}{{ $d->note ? ' · ' . $d->note : '' }}
                            </div>
                        </div>
                        <div style="text-align: right; white-space: nowrap; font-weight: 700; color: #16a34a;">
                            -{{ $d->type === \App\Models\StudentDiscount::TYPE_PERCENT ? rtrim(rtrim(number_format((float) $d->value, 2, '.', ''), '0'), '.') . ' %' : $money($d->value) }}
                        </div>
                    </div>
                @empty
                    <div style="font-size: 13px; color: #9ca3af;">Индивидуальных скидок нет</div>
                @endforelse
            </div>

        </div>
    </div>
</div>
