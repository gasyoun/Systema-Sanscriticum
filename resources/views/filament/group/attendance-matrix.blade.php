@php
    /** @var \App\Models\Group $group */
    /** @var array $data */
    $schedules = $data['schedules'];
    $students = $data['students'];
    $status = $data['status'];

    // Маркер ячейки по статусу.
    $cell = fn (string $s) => match ($s) {
        'present' => ['✓', '#16a34a', '#dcfce7'],
        'clicked' => ['~', '#b45309', '#fef3c7'],
        default => ['—', '#d1d5db', 'transparent'],
    };
@endphp

{{-- Тёмная тема: аддитивные правила, светлую тему не трогают (цвета по [style*=…]).
     Липкая первая колонка на background:#fff иначе осталась бы белой в тёмной теме. --}}
<style>
    .dark .att-matrix [style*="background: #fff"] { background: #16181d !important; }
    .dark .att-matrix [style*="border-bottom: 2px solid #e5e7eb"] { border-bottom-color: rgba(255,255,255,.15) !important; }
    .dark .att-matrix [style*="border-bottom: 1px solid #f3f4f6"] { border-bottom-color: rgba(255,255,255,.07) !important; }
    .dark .att-matrix [style*="color: #111827"] { color: #f3f4f6 !important; }
    .dark .att-matrix [style*="color: #6b7280"] { color: #9ca3af !important; }
</style>

<div class="att-matrix" style="overflow-x: auto; font-size: 12px;">
    @if($schedules->isEmpty() || $students->isEmpty())
        <div style="color: #9ca3af; padding: 12px;">Нет занятий или студентов за период.</div>
    @else
        <div style="display: flex; gap: 12px; margin-bottom: 10px; color: #6b7280;">
            <span><b style="color:#16a34a;">✓</b> пришёл (Zoom)</span>
            <span><b style="color:#b45309;">~</b> перешёл по ссылке</span>
            <span><b style="color:#d1d5db;">—</b> не был</span>
        </div>
        <table style="border-collapse: collapse; white-space: nowrap;">
            <thead>
                <tr>
                    <th style="position: sticky; left: 0; background: #fff; text-align: left; padding: 6px 10px; border-bottom: 2px solid #e5e7eb; z-index: 1;">Студент</th>
                    @foreach($schedules as $s)
                        <th style="padding: 6px 8px; border-bottom: 2px solid #e5e7eb; color: #6b7280; font-weight: 600;" title="{{ $s->title }}">
                            {{ $s->start?->format('d.m') }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($students as $student)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="position: sticky; left: 0; background: #fff; padding: 6px 10px; color: #111827;">{{ $student->name }}</td>
                        @foreach($schedules as $s)
                            @php [$mark, $fg, $bg] = $cell($status[$student->id][$s->id] ?? 'absent'); @endphp
                            <td style="text-align: center; padding: 6px 8px; color: {{ $fg }}; background: {{ $bg }}; font-weight: 700;">{{ $mark }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
