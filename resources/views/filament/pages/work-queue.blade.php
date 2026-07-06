<x-filament-panels::page>
    @php
        $leads = $this->leads;
        $promises = $this->promises;
        $stuck = $this->stuck;
        $support = $this->support;
    @endphp

    <style>
        .wq-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .wq-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 12px; overflow: hidden; }
        .dark .wq-card { background: #18181b; border-color: rgba(255,255,255,.08); }
        .wq-card-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,.06); }
        .dark .wq-card-head { border-bottom-color: rgba(255,255,255,.06); }
        .wq-title { font-weight: 700; font-size: 14px; color: #111827; }
        .dark .wq-title { color: #f4f4f5; }
        .wq-count { background: #f59e0b; color: #1c1917; font-weight: 700; font-size: 12px; padding: 2px 10px; border-radius: 99px; }
        .wq-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 16px; border-bottom: 1px solid rgba(0,0,0,.04); }
        .dark .wq-row { border-bottom-color: rgba(255,255,255,.04); }
        .wq-row:last-child { border-bottom: none; }
        .wq-main { min-width: 0; }
        .wq-name { font-size: 13px; font-weight: 600; color: #1f2937; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dark .wq-name { color: #e5e7eb; }
        .wq-meta { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .wq-btn { flex-shrink: 0; font-size: 12px; font-weight: 600; padding: 5px 11px; border-radius: 7px; cursor: pointer; border: 1px solid #f59e0b; background: #f59e0b; color: #1c1917; text-decoration: none; white-space: nowrap; }
        .wq-btn.ghost { background: transparent; color: #b45309; }
        .wq-empty { padding: 20px 16px; text-align: center; color: #9ca3af; font-size: 13px; }
    </style>

    <div class="wq-grid">

        {{-- 1. Лиды на контакт сегодня --}}
        <div class="wq-card">
            <div class="wq-card-head">
                <span class="wq-title">📞 Лиды на контакт сегодня</span>
                <span class="wq-count">{{ $leads->count() }}</span>
            </div>
            @forelse($leads as $lead)
                <div class="wq-row" wire:key="lead-{{ $lead->id }}">
                    <div class="wq-main">
                        <div class="wq-name">{{ $lead->name ?: ($lead->contact ?: $lead->email ?: 'Лид #'.$lead->id) }}</div>
                        <div class="wq-meta">
                            {{ \App\Models\Lead::STATUSES[$lead->status] ?? $lead->status }}
                            @if($lead->next_contact_at) · контакт {{ \Illuminate\Support\Carbon::parse($lead->next_contact_at)->format('d.m.Y') }} @endif
                        </div>
                    </div>
                    <a href="{{ $this->leadUrl($lead->id) }}" class="wq-btn" target="_blank">Открыть лид</a>
                </div>
            @empty
                <div class="wq-empty">На сегодня всё сделано 🎉</div>
            @endforelse
        </div>

        {{-- 2. Обещания просрочены/истекают --}}
        <div class="wq-card">
            <div class="wq-card-head">
                <span class="wq-title">⏳ Обещания оплаты</span>
                <span class="wq-count">{{ $promises->count() }}</span>
            </div>
            @forelse($promises as $promise)
                <div class="wq-row" wire:key="promise-{{ $promise->id }}">
                    <div class="wq-main">
                        <div class="wq-name">{{ $promise->user?->name ?: ($promise->user?->email ?: 'Студент') }}</div>
                        <div class="wq-meta">
                            {{ $promise->course?->title ?? 'Курс' }}
                            @if($promise->promised_at) · обещал {{ $promise->promised_at->format('d.m.Y') }} @endif
                            @if($promise->isOverdueOrExpired()) · <span style="color:#dc2626;">просрочено</span> @endif
                        </div>
                    </div>
                    <button type="button" class="wq-btn" wire:click="remindPromise({{ $promise->id }})">Напомнить</button>
                </div>
            @empty
                <div class="wq-empty">Нет активных обещаний</div>
            @endforelse
        </div>

        {{-- 3. Застрявшие / риск оттока --}}
        <div class="wq-card">
            <div class="wq-card-head">
                <span class="wq-title">🚪 Риск оттока</span>
                <span class="wq-count">{{ $stuck->count() }}</span>
            </div>
            @forelse($stuck as $student)
                <div class="wq-row" wire:key="stuck-{{ $student->id }}">
                    <div class="wq-main">
                        <div class="wq-name">{{ $student->name ?: ($student->email ?: 'Студент #'.$student->id) }}</div>
                        <div class="wq-meta">{{ implode(' · ', \App\Services\StuckStudentsReport::reasonsFor($student)) ?: 'Неактивен' }}</div>
                    </div>
                    <a href="{{ $this->studentUrl($student->id) }}" class="wq-btn ghost" target="_blank">Карточка</a>
                </div>
            @empty
                <div class="wq-empty">Никто не застрял</div>
            @endforelse
        </div>

        {{-- 4. Поддержка без ответа --}}
        <div class="wq-card">
            <div class="wq-card-head">
                <span class="wq-title">💬 Поддержка без ответа &gt; {{ \App\Services\WorkQueueReport::SUPPORT_SLA_HOURS }} ч</span>
                <span class="wq-count">{{ $support->count() }}</span>
            </div>
            @forelse($support as $item)
                @php $conv = $item['conversation']; @endphp
                <div class="wq-row" wire:key="support-{{ $conv->id }}">
                    <div class="wq-main">
                        <div class="wq-name">{{ $conv->user?->name ?: ($conv->user?->email ?: 'Студент') }}</div>
                        <div class="wq-meta">ждёт с {{ $item['waiting_since']->diffForHumans() }}</div>
                    </div>
                    <a href="{{ $this->dialogUrl($conv->user_id) }}" class="wq-btn">Открыть диалог</a>
                </div>
            @empty
                <div class="wq-empty">Все ответы даны 👌</div>
            @endforelse
        </div>

    </div>
</x-filament-panels::page>
