@php
    /** @var \App\Models\Schedule $schedule */
    /** @var array $data */
    $summary = $data['summary'];
    $roster = $data['roster'];
    $guests = $data['guests'];

    $badge = fn (string $status) => match ($status) {
        'present' => ['Пришел', '#16a34a', '#dcfce7'],
        'clicked' => ['Перешел по ссылке', '#b45309', '#fef3c7'],
        default => ['Не был', '#6b7280', '#f3f4f6'],
    };

    $noticeBadge = fn (?string $status) => match ($status) {
        'absent' => ['Не сможет', '#b91c1c', '#fee2e2'],
        'uncertain' => ['Не уверен', '#a16207', '#fef9c3'],
        'late' => ['Опоздает', '#c2410c', '#ffedd5'],
        'leave_early' => ['Уйдёт раньше', '#6d28d9', '#ede9fe'],
        default => null,
    };
@endphp

{{-- Тёмная тема: аддитивные правила, светлую тему не трогают (цвета по [style*=…]). --}}
<style>
    .dark .att-panel [style*="border-bottom: 1px solid #e5e7eb"] { border-bottom-color: rgba(255,255,255,.12) !important; }
    .dark .att-panel [style*="border-bottom: 1px solid #f3f4f6"] { border-bottom-color: rgba(255,255,255,.07) !important; }
    .dark .att-panel [style*="color: #111827"] { color: #f3f4f6 !important; }
    .dark .att-panel [style*="color: #374151"] { color: #d1d5db !important; }
    .dark .att-panel [style*="color: #6b7280"] { color: #9ca3af !important; }
    .dark .att-panel [style*="background: #f3f4f6"] { background: rgba(255,255,255,.1) !important; }
</style>

<div class="att-panel" style="display: flex; flex-direction: column; gap: 16px; font-size: 13px;">
    {{-- Сводка --}}
    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        @php
            $chips = [
                ['Ожидали', $summary['expected'], '#e0f2fe', '#0369a1'],
                ['Пришли', $summary['present'], '#dcfce7', '#16a34a'],
                ['По ссылке', $summary['clicked'], '#fef3c7', '#b45309'],
                ['Не были', $summary['absent'], '#f3f4f6', '#6b7280'],
                ['Предупредили', $summary['noticed'] ?? 0, '#ffedd5', '#c2410c'],
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
                    <th style="padding: 6px 8px;">Предупредил</th>
                    <th style="padding: 6px 8px;">Минут</th>
                    <th style="padding: 6px 8px;">Источник</th>
                </tr>
            </thead>
            <tbody>
                @foreach($roster as $row)
                    @php
                        [$label, $fg, $bg] = $badge($row['status']);
                        $nBadge = $noticeBadge($row['notice_status'] ?? null);
                    @endphp
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 6px 8px; color: #111827;">{{ $row['user']->name }}</td>
                        <td style="padding: 6px 8px;">
                            <span style="background: {{ $bg }}; color: {{ $fg }}; padding: 2px 8px; border-radius: 99px; font-weight: 600; white-space: nowrap;">{{ $label }}</span>
                        </td>
                        <td style="padding: 6px 8px;">
                            @if($nBadge)
                                <span style="background: {{ $nBadge[2] }}; color: {{ $nBadge[1] }}; padding: 2px 8px; border-radius: 99px; font-weight: 600; white-space: nowrap;">{{ $nBadge[0] }}</span>
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
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
