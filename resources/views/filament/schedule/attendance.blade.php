@php
    /** @var \App\Models\Schedule $schedule */
    /** @var array $data */
    $summary = $data['summary'];
    $roster = $data['roster'];
    $guests = $data['guests'];

    $badge = fn (string $status) => match ($status) {
        'present' => ['Пришёл', '#16a34a', '#dcfce7'],
        'clicked' => ['Перешёл по ссылке', '#b45309', '#fef3c7'],
        default => ['Не был', '#6b7280', '#f3f4f6'],
    };
@endphp

<div style="display: flex; flex-direction: column; gap: 16px; font-size: 13px;">
    {{-- Сводка --}}
    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        @php
            $chips = [
                ['Ожидали', $summary['expected'], '#e0f2fe', '#0369a1'],
                ['Пришли', $summary['present'], '#dcfce7', '#16a34a'],
                ['По ссылке', $summary['clicked'], '#fef3c7', '#b45309'],
                ['Не были', $summary['absent'], '#f3f4f6', '#6b7280'],
                ['Гости', $summary['guests'], '#ede9fe', '#6d28d9'],
            ];
        @endphp
        @foreach($chips as [$label, $value, $bg, $fg])
            <span style="background: {{ $bg }}; color: {{ $fg }}; padding: 4px 10px; border-radius: 99px; font-weight: 700;">{{ $label }}: {{ $value }}</span>
        @endforeach
    </div>

    {{-- Ростер --}}
    @if($roster->isEmpty())
        <div style="color: #9ca3af; padding: 12px;">У занятия нет привязанной группы/курса — список ожидаемых пуст.</div>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #6b7280; border-bottom: 1px solid #e5e7eb;">
                    <th style="padding: 6px 8px;">Студент</th>
                    <th style="padding: 6px 8px;">Статус</th>
                    <th style="padding: 6px 8px;">Минут</th>
                    <th style="padding: 6px 8px;">Источник</th>
                </tr>
            </thead>
            <tbody>
                @foreach($roster as $row)
                    @php [$label, $fg, $bg] = $badge($row['status']); @endphp
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 6px 8px; color: #111827;">{{ $row['user']->name }}</td>
                        <td style="padding: 6px 8px;">
                            <span style="background: {{ $bg }}; color: {{ $fg }}; padding: 2px 8px; border-radius: 99px; font-weight: 600; white-space: nowrap;">{{ $label }}</span>
                        </td>
                        <td style="padding: 6px 8px; color: #374151;">{{ $row['minutes'] !== null ? $row['minutes'] : '—' }}</td>
                        <td style="padding: 6px 8px; color: #9ca3af;">{{ $row['click_source'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Неопознанные гости Zoom --}}
    @if($guests->isNotEmpty())
        <div>
            <div style="font-weight: 700; color: #6d28d9; margin-bottom: 6px;">Неопознанные участники Zoom ({{ $guests->count() }})</div>
            <div style="display: flex; flex-direction: column; gap: 4px; color: #374151;">
                @foreach($guests as $g)
                    <div>👤 {{ $g->name ?: 'Без имени' }}@if($g->email) · {{ $g->email }}@endif @if($g->duration_seconds) · {{ (int) round($g->duration_seconds / 60) }} мин @endif</div>
                @endforeach
            </div>
            <div style="color: #9ca3af; margin-top: 6px; font-size: 12px;">Зашли в Zoom под именем/почтой, не совпавшими с аккаунтом. Если это студенты — попросите заходить под почтой кабинета.</div>
        </div>
    @endif
</div>
