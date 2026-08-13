<x-filament-panels::page>
    <style>
        .lc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .lc-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 12px; padding: 14px 16px; }
        .dark .lc-card { background: #18181b; border-color: rgba(255,255,255,.08); }
        .lc-k { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .lc-v { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .lc-meta { font-size: 12px; color: #6b7280; margin-top: 8px; line-height: 1.45; }
        .lc-btn { font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 8px; cursor: pointer; border: 1px solid #f59e0b; background: #f59e0b; color: #1c1917; }
        .lc-btn.ghost { background: transparent; color: #b45309; }
        .lc-note { font-size: 13px; color: #6b7280; margin: 0 0 16px; }
    </style>

    <p class="lc-note">
        Один запрос на правило для dry-run и для живой отправки. Recovery и suppression
        не попадают в eligible. Кнопка ниже только готовит <strong>черновик</strong> —
        письмо уходит только после «Отправить» в Email-кампаниях.
    </p>

    <div style="display:flex; gap:8px; margin-bottom:16px;">
        <button type="button" wire:click="refreshCensus" class="lc-btn ghost">Пересчитать</button>
        <button type="button" wire:click="prepareDrafts" class="lc-btn"
                wire:confirm="Создать или обновить черновики? Письма не отправятся.">
            Подготовить черновики
        </button>
    </div>

    <div class="lc-grid">
        @foreach ($rows as $row)
            <div class="lc-card">
                <div class="lc-k">{{ $row['rule'] }} · v{{ $row['version'] }}</div>
                <div class="lc-v">{{ $row['eligible'] }} eligible</div>
                <div class="lc-meta">
                    candidates {{ $row['candidates'] }} ·
                    excluded {{ $row['excluded'] }} ·
                    suppressed {{ $row['suppressed'] }} ·
                    recovery {{ $row['recovery'] }} ·
                    dedup {{ $row['deduplicated'] }}
                    @if (! empty($row['campaign_id']))
                        <br>draft campaign #{{ $row['campaign_id'] }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
