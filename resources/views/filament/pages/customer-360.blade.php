<x-filament-panels::page>
    @php
        /** @var \App\Services\Crm\Customer360Snapshot|null $snap */
        $snap = $this->getSnapshot();
        $next = $snap?->nextAction;
        $pipe = $snap?->pipeline;
        $access = $snap?->accessPayment;
        $support = $snap?->support;
        $learning = $snap?->learning;
        $attr = $snap?->attribution;
    @endphp

    <style>
        .c360-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .c360-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 12px; padding: 14px 16px; }
        .dark .c360-card { background: #18181b; border-color: rgba(255,255,255,.08); }
        .c360-k { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .c360-v { font-size: 15px; font-weight: 700; color: #111827; margin-top: 4px; }
        .dark .c360-v { color: #f4f4f5; }
        .c360-src { font-size: 11px; color: #6b7280; margin-top: 6px; }
        .c360-src a { color: #b45309; text-decoration: underline; }
        .c360-next { border-color: #f59e0b; }
        .c360-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.05); font-size: 13px; }
        .dark .c360-row { border-bottom-color: rgba(255,255,255,.06); }
        .c360-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-top: 12px; }
        .c360-form input, .c360-form select, .c360-form textarea { border: 1px solid rgba(0,0,0,.15); border-radius: 8px; padding: 6px 8px; font-size: 13px; background: transparent; }
        .c360-btn { font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 7px; cursor: pointer; border: 1px solid #f59e0b; background: #f59e0b; color: #1c1917; }
        .c360-btn.ghost { background: transparent; color: #b45309; }
    </style>

    {{ $this->form }}

    @if($snap === null)
        <div class="c360-card">
            <div class="c360-k">Карточка</div>
            <div class="c360-v">Выберите студента или заявку</div>
            <p class="c360-src">Поиск сверху, либо ссылка со страницы студента / заявки. Факты не копируются — каждая строка ведёт к своему владельцу.</p>
        </div>
    @else
        <h2 class="text-xl font-bold mb-3">{{ $snap->displayName }}</h2>

        <div class="c360-grid">
            <div class="c360-card c360-next" data-testid="c360-next">
                <div class="c360-k">Следующее действие</div>
                <div class="c360-v">{{ $next['label'] ?? '—' }}</div>
                <div class="c360-src">
                    источник: {{ $next['source'] ?? '—' }}
                    @if(!empty($next['url']))
                        · <a href="{{ $next['url'] }}">открыть владельца</a>
                    @endif
                </div>
                @if(!empty($next['task_id']))
                    <button type="button" class="c360-btn" wire:click="completeTask({{ (int) $next['task_id'] }})">Закрыть задачу</button>
                @endif
            </div>

            <div class="c360-card" data-testid="c360-pipeline">
                <div class="c360-k">Воронка</div>
                <div class="c360-v">{{ $pipe['label'] ?? 'нет стадии' }}</div>
                <div class="c360-src">
                    владелец: {{ $pipe['owner'] ?? '—' }}
                    @if(!empty($pipe['assignee'])) · {{ $pipe['assignee'] }} @endif
                    @if(!empty($pipe['url']))
                        · <a href="{{ $pipe['url'] }}">к доске / заявке</a>
                    @endif
                </div>
            </div>

            <div class="c360-card" data-testid="c360-access">
                <div class="c360-k">Оплата / доступ (только чтение)</div>
                <div class="c360-v">{{ $access['state'] ?? 'нет данных' }}</div>
                <div class="c360-src">
                    владелец: {{ $access['owner'] ?? 'Payment' }}
                    @if(!empty($access['course'])) · {{ $access['course'] }} @endif
                    @if(!empty($access['url']))
                        · <a href="{{ $access['url'] }}">платёж</a>
                    @endif
                </div>
            </div>

            <div class="c360-card" data-testid="c360-support">
                <div class="c360-k">Поддержка</div>
                <div class="c360-v">{{ $support['outcome'] ?? 'нет диалогов' }}</div>
                <div class="c360-src">
                    тема: {{ $support['topic'] ?? '—' }}
                    @if(!empty($support['url']))
                        · <a href="{{ $support['url'] }}">Helpdesk</a>
                    @endif
                </div>
            </div>

            <div class="c360-card" data-testid="c360-learning">
                <div class="c360-k">Учёба</div>
                <div class="c360-v">{{ $learning['event_type'] ?? 'нет событий' }}</div>
                <div class="c360-src">владелец: ActivityEvent</div>
            </div>

            <div class="c360-card" data-testid="c360-attribution">
                <div class="c360-k">Атрибуция</div>
                <div class="c360-v">{{ $attr['utm_source'] ?? $attr['partner'] ?? '—' }}</div>
                <div class="c360-src">
                    владелец: {{ $attr['owner'] ?? '—' }}
                    @if(!empty($attr['utm_campaign'])) · {{ $attr['utm_campaign'] }} @endif
                    @if(!empty($attr['url']))
                        · <a href="{{ $attr['url'] }}">заявка</a>
                    @endif
                </div>
            </div>
        </div>

        @if($snap->primaryDeal())
            <div class="c360-card" style="margin-bottom:16px">
                <div class="c360-k">Сменить стадию сделки (через Deal::moveToStage)</div>
                <div class="c360-form">
                    <select wire:model="moveStageId">
                        <option value="">стадия…</option>
                        @foreach(\App\Models\DealStage::query()->ordered()->get() as $stage)
                            <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="c360-btn" wire:click="moveStage">Перевести</button>
                </div>
            </div>
            @if(config('features.crm_trial_booking') && $snap->primaryDeal()?->isTrial())
                <div class="c360-card" style="margin-bottom:16px" data-testid="c360-trial-outcome">
                    <div class="c360-k">Пробник — исход (staff override)</div>
                    <div class="c360-form">
                        <select wire:model="trialOutcome">
                            <option value="">исход…</option>
                            <option value="booked">записан</option>
                            <option value="attended">был</option>
                            <option value="no_show">не пришёл</option>
                            <option value="converted">конвертирован</option>
                        </select>
                        <button type="button" class="c360-btn" wire:click="applyTrialOutcome">Сохранить исход</button>
                    </div>
                </div>
            @endif
        @endif

        <div class="c360-card" style="margin-bottom:16px">
            <div class="c360-k">Новая задача (FollowUpTask)</div>
            <div class="c360-form">
                <select wire:model="taskType">
                    @foreach(\App\Models\FollowUpTask::types() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="date" wire:model="taskDue">
                <input type="text" wire:model="taskNote" placeholder="заметка">
                <button type="button" class="c360-btn" wire:click="createTask">Поставить</button>
            </div>
        </div>

        <div class="c360-card">
            <div class="c360-k">Лента (канонические события, без зеркала)</div>
            @forelse($snap->timeline as $row)
                <div class="c360-row">
                    <div>
                        <strong>{{ $row['title'] }}</strong>
                        <div class="c360-src">{{ $row['owner'] }} · {{ optional($row['at'])->format('d.m.Y H:i') }}</div>
                    </div>
                    @if(!empty($row['url']))
                        <a class="c360-btn ghost" href="{{ $row['url'] }}">источник</a>
                    @endif
                </div>
            @empty
                <p class="c360-src">Событий пока нет.</p>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
