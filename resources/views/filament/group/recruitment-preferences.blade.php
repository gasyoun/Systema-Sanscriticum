@php
    /** @var \App\Models\Group $group */
    /** @var \Illuminate\Support\Collection $entries */
@endphp

<style>
    .dark .rec-prefs [style*="background: #fff"] { background: #16181d !important; }
    .dark .rec-prefs [style*="border-bottom: 1px solid #f3f4f6"] { border-bottom-color: rgba(255,255,255,.07) !important; }
    .dark .rec-prefs [style*="color: #111827"] { color: #f3f4f6 !important; }
    .dark .rec-prefs [style*="color: #6b7280"] { color: #9ca3af !important; }
</style>

<div class="rec-prefs" style="font-size: 13px;">
    @if(! $group->intake)
        <div style="color: #9ca3af; padding: 12px;">Группа не привязана к набору — предпочтений нет.</div>
    @elseif($entries->isEmpty())
        <div style="color: #9ca3af; padding: 12px;">Заявок в наборе «{{ $group->intake->label }}» пока нет.</div>
    @else
        <div style="color: #6b7280; margin-bottom: 10px;">
            Свободный текст пожеланий из заявок набора «{{ $group->intake->label }}»
            (колонка «Время и дни» таблицы набора) — куратор формирует группу под
            самый популярный слот вручную.
        </div>
        <table style="border-collapse: collapse; width: 100%;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid #e5e7eb; color: #111827;">Имя</th>
                    <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid #e5e7eb; color: #111827;">Пожелание по времени</th>
                    <th style="text-align: left; padding: 6px 10px; border-bottom: 2px solid #e5e7eb; color: #111827;">Часовой пояс</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entries as $entry)
                    <tr>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #f3f4f6; color: #111827;">{{ $entry->name }}</td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #f3f4f6; color: #111827;">{{ $entry->preferred_schedule ?: '—' }}</td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #f3f4f6; color: #6b7280;">{{ $entry->timezone_note ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
