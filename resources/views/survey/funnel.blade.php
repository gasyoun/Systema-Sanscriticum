@extends('layouts.shop')

@section('title', 'Воронка анкеты — '.$definition['title'])

@section('content')
<div class="container mx-auto px-4 py-10 max-w-4xl">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-extrabold text-white">{{ $definition['title'] }}</h1>
            <p class="mt-1 text-sm text-slate-400">Воронка и агрегаты волны <code class="text-brand">{{ $slug }}</code>. Только количества — контакты и тексты в выгрузке CSV.</p>
        </div>
        <a href="{{ route('survey.export', $slug) }}" class="px-4 py-2 rounded-xl border border-[#1F2636] text-sm font-bold text-slate-200 hover:border-slate-500 transition-colors">CSV-выгрузка</a>
    </div>

    <div class="mt-8 grid grid-cols-2 md:grid-cols-5 gap-3">
        @foreach(['sent' => 'Приглашений ушло', 'opened' => 'Открыли (сессии)', 'started' => 'Начали (сессии)', 'completed' => 'Завершили (ответы)'] as $key => $label)
            <div class="bg-[#111622] border border-[#1F2636] rounded-2xl px-4 py-4">
                <div class="text-3xl font-extrabold text-white">{{ $counts[$key] }}</div>
                <div class="mt-1 text-xs font-bold text-slate-400">{{ $label }}</div>
            </div>
        @endforeach
        <div class="bg-[#111622] border border-[#1F2636] rounded-2xl px-4 py-4">
            <div class="text-3xl font-extrabold text-brand">{{ $counts['sent'] > 0 ? round(100 * $counts['completed'] / $counts['sent']).'%' : '—' }}</div>
            <div class="mt-1 text-xs font-bold text-slate-400">Завершение от отправленных</div>
        </div>
    </div>

    <div class="mt-6 grid md:grid-cols-2 gap-6">
        <div class="bg-[#111622] border border-[#1F2636] rounded-2xl p-5">
            <h2 class="text-sm font-extrabold text-slate-200 uppercase tracking-wide">Приглашения по статусу</h2>
            <table class="mt-3 w-full text-sm">
                <tbody>
                @foreach(['queued', 'sent', 'failed', 'unknown'] as $status)
                    <tr class="border-t border-[#1F2636]">
                        <td class="py-2 text-slate-400">{{ $status }}</td>
                        <td class="py-2 text-right font-bold text-white">{{ $invitationCounts[$status] ?? 0 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-[#111622] border border-[#1F2636] rounded-2xl p-5">
            <h2 class="text-sm font-extrabold text-slate-200 uppercase tracking-wide">Доходили ли до страниц</h2>
            @if($pageCounts->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Пока нет переходов по страницам (одностраничные анкеты страниц не имеют).</p>
            @else
                <table class="mt-3 w-full text-sm">
                    <tbody>
                    @foreach($pageCounts as $page => $sessions)
                        <tr class="border-t border-[#1F2636]">
                            <td class="py-2 text-slate-400">Страница {{ $page }}</td>
                            <td class="py-2 text-right font-bold text-white">{{ $sessions }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="mt-6 bg-[#111622] border border-[#1F2636] rounded-2xl p-5">
        <h2 class="text-sm font-extrabold text-slate-200 uppercase tracking-wide">Последние 14 дней</h2>
        @if($daily->isEmpty())
            <p class="mt-3 text-sm text-slate-500">Событий за последние 14 дней нет.</p>
        @else
            <table class="mt-3 w-full text-sm">
                <thead>
                <tr class="text-left text-xs font-bold text-slate-500 uppercase">
                    <th class="py-2">День</th>
                    <th class="py-2">sent</th>
                    <th class="py-2">opened</th>
                    <th class="py-2">started</th>
                    <th class="py-2">page</th>
                    <th class="py-2">completed</th>
                </tr>
                </thead>
                <tbody>
                @foreach($daily as $day => $byEvent)
                    <tr class="border-t border-[#1F2636]">
                        <td class="py-2 text-slate-400">{{ \Illuminate\Support\Carbon::parse($day)->format('d.m') }}</td>
                        <td class="py-2 font-bold text-white">{{ $byEvent[\App\Models\SurveyEvent::SENT] ?? 0 }}</td>
                        <td class="py-2 font-bold text-white">{{ $byEvent[\App\Models\SurveyEvent::OPENED] ?? 0 }}</td>
                        <td class="py-2 font-bold text-white">{{ $byEvent[\App\Models\SurveyEvent::STARTED] ?? 0 }}</td>
                        <td class="py-2 font-bold text-white">{{ $byEvent[\App\Models\SurveyEvent::PAGE] ?? 0 }}</td>
                        <td class="py-2 font-bold text-brand">{{ $byEvent[\App\Models\SurveyEvent::COMPLETED] ?? 0 }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
