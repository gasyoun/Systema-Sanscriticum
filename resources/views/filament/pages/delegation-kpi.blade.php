<x-filament-panels::page>
    @php($s = $snap)
    @php($ring = [
        'success' => 'ring-success-600/20 dark:ring-success-400/30 bg-success-50 dark:bg-success-500/10',
        'warning' => 'ring-warning-600/20 dark:ring-warning-400/30 bg-warning-50 dark:bg-warning-500/10',
        'danger' => 'ring-danger-600/20 dark:ring-danger-400/30 bg-danger-50 dark:bg-danger-500/10',
        'gray' => 'ring-gray-950/5 dark:ring-white/10 bg-gray-50 dark:bg-white/5',
    ])
    @php($valColor = [
        'success' => 'text-success-700 dark:text-success-300',
        'warning' => 'text-warning-700 dark:text-warning-300',
        'danger' => 'text-danger-700 dark:text-danger-300',
        'gray' => 'text-gray-900 dark:text-white',
    ])
    @php($dot = [
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'danger' => 'bg-danger-500',
        'gray' => 'bg-gray-300',
    ])

    {{-- ── Общий статус панели ── --}}
    @if ($s['ok'])
        <div class="rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30 text-sm text-success-700 dark:text-success-300">
            <span class="font-semibold">Всё под контролем.</span>
            Красных флагов нет — можно вести без собственника. Разбор по ритму обзора (см. docs/FINANCE_REVIEW_RHYTHM.md).
        </div>
    @else
        <div class="rounded-xl bg-danger-50 p-4 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/30">
            <div class="flex items-center gap-2 text-danger-700 dark:text-danger-400 font-semibold">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
                Есть красные флаги — требуется решение
            </div>
            <ul class="mt-2 list-disc pl-6 text-sm text-danger-700 dark:text-danger-300 space-y-1">
                @foreach ($s['alerts'] as $alert)
                    <li>{{ $alert }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── KPI-карточки всех фаз ── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($s['cards'] as $card)
            <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getUrl().'/'.$card['page'] }}"
               class="block rounded-xl p-4 ring-1 {{ $ring[$card['level']] }} transition hover:ring-2">
                <div class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$card['level']] }}"></span>
                    {{ $card['label'] }}
                </div>
                <div class="mt-1 text-xl font-bold {{ $valColor[$card['level']] }}">{{ $card['value'] }}</div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-semibold">Красный флаг:</span> {{ $card['red_flag'] }}
                </div>
            </a>
        @endforeach
    </div>

    {{-- ── Ритм обзора (закреплён, иначе повторим провал «Лингвистик») ── --}}
    <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4 text-sm text-gray-600 dark:text-gray-300">
        <div class="font-semibold text-gray-700 dark:text-gray-200 mb-1">Ритм обзора</div>
        <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
            <div><b>Еженедельно</b> (владелец цифр — делегированный финдир/бухгалтер, роль RoleGate::finance): дебиторка против порога (C), окупаемость привлечения (A). Красный флаг → решение в тот же день.</div>
            <div><b>Ежемесячно</b>: прибыль по начислению и маржа (B), распределение по фондам и обеспеченность резерва (D), отложенная выручка (B).</div>
            <div><b>Собственник</b> — ≤1 встреча в неделю, только стратегия; в операционку заходит лишь на красный флаг этой панели.</div>
            <div>Полный чек-лист и пороги — docs/FINANCE_REVIEW_RHYTHM.md. Недельный дайджест шлёт <code>finance:kpi-digest</code>.</div>
        </div>
        <div class="mt-2 text-xs text-gray-400 dark:text-gray-500">Снимок на {{ $s['as_of'] }}.</div>
    </div>
</x-filament-panels::page>
