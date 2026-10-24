@php
    $STATUS_RU = [
        'draft' => 'черновик',
        'submitted' => 'на проверке',
        'needs_revision' => 'на доработку',
        'accepted' => 'принято',
    ];
@endphp

<div style="font-family:inherit;max-width:1100px">

    {{-- Шапка: кто и когда --}}
    <div style="display:flex;flex-wrap:wrap;gap:10px 26px;margin-bottom:18px;padding:14px 18px;background:#f8fafc;border-radius:12px;font-size:14px;color:#334155">
        <span><b>Регистрация:</b> {{ $details['registered_at'] ?? '—' }}</span>
        <span><b>Записан на курс:</b> {{ $details['enrolled_at'] ?? '—' }}</span>
        <span><b>Источник:</b> {{ $details['signup_source'] ?? '—' }}</span>
        <span><b>Email:</b> {{ $details['email'] }}</span>
    </div>

    {{-- Уроки --}}
    <h3 style="margin:0 0 10px;font-size:16px;font-weight:800;color:#111827">Уроки</h3>
    <div style="margin-bottom:20px">
        @foreach ($details['lessons'] as $lesson)
            <div style="display:flex;gap:10px;align-items:baseline;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:14px">
                <span style="color:{{ $lesson['completed'] ? '#059669' : '#cbd5e1' }};font-weight:800;width:18px">
                    {{ $lesson['completed'] ? '✓' : '○' }}
                </span>
                <span style="flex:1;color:{{ $lesson['completed'] ? '#111827' : '#94a3b8' }}">{{ $lesson['title'] }}</span>
                @if ($lesson['date'])
                    <span style="color:#94a3b8;font-size:12px">{{ $lesson['date'] }}</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Попытки квизов --}}
    <h3 style="margin:22px 0 10px;font-size:16px;font-weight:800;color:#111827">Попытки квизов</h3>
    @if (count($details['attempts']) > 0)
        <div style="margin-bottom:20px">
            @foreach ($details['attempts'] as $a)
                <div style="display:flex;gap:10px;align-items:baseline;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:14px">
                    <span style="min-width:60px;color:{{ $a['passed'] ? '#059669' : '#d97706' }};font-weight:800">
                        {{ $a['score'] }}/{{ $a['total'] }}
                    </span>
                    <span style="flex:1;color:#111827">{{ $a['title'] }}</span>
                    <span style="color:#94a3b8;font-size:12px">{{ $a['date'] }}</span>
                </div>
            @endforeach
        </div>
    @else
        <p style="margin:0 0 20px;font-size:14px;color:#94a3b8">Попыток пока не было.</p>
    @endif

    {{-- Домашки --}}
    @if (count($details['homework']) > 0)
        <h3 style="margin:22px 0 10px;font-size:16px;font-weight:800;color:#111827">Домашние задания</h3>
        <div style="margin-bottom:20px">
            @foreach ($details['homework'] as $h)
                @php($stRu = $STATUS_RU[$h['status']] ?? $h['status'])
                <div style="display:flex;gap:10px;align-items:baseline;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:14px">
                    <span style="flex:1;color:#111827">{{ $h['lesson'] }}</span>
                    <span style="min-width:110px;font-weight:700;color:{{ $h['status'] === 'accepted' ? '#059669' : ($h['status'] === 'needs_revision' ? '#d97706' : '#334155') }}">{{ $stRu }}</span>
                    <span style="color:#94a3b8;font-size:12px">{{ $h['date'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Промокод --}}
    @if ($details['promo'])
        <div style="margin:22px 0;padding:16px 18px;border-radius:12px;background:#eef2ff;border:1px solid #c7d2fe">
            <p style="margin:0 0 6px;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#4f46e5">Награда: промокод на грамматику</p>
            <p style="margin:0;font-size:18px;font-weight:800;font-family:monospace;letter-spacing:.08em;color:#312e81">{{ $details['promo']['code'] }}</p>
            <p style="margin:6px 0 0;font-size:13px;color:#475569">
                Выдан {{ $details['promo']['created'] }} · действует до {{ $details['promo']['expires'] }}
                @if ($details['promo']['redeemed'])
                    · <b style="color:#059669">погашен: оплата {{ $details['promo']['redeemed']['date'] }} на сумму {{ number_format($details['promo']['redeemed']['amount'], 0, ',', ' ') }} ₽</b>
                @endif
            </p>
        </div>
    @endif

</div>
