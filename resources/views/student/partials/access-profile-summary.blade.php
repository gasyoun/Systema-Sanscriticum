{{-- H2386: compact access status near bots / profile (flag-gated). --}}
@php
    $summary = $summary ?? null;
    $enabled = $enabled ?? (bool) config('features.access_self_service', false);
@endphp
@if ($enabled && is_array($summary))
    <div class="mb-6 rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm"
         id="access-summary">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-[10px] font-extrabold uppercase tracking-widest text-gray-400 mb-1">Мой доступ</p>
                <p class="text-sm font-bold text-gray-900">{{ $summary['line'] ?? '' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if (empty($summary['bot_connected']))
                    <a href="{{ route('telegram.connect') }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[#0088cc] text-white text-xs font-bold">
                        Подключить бота
                    </a>
                @endif
                <a href="{{ route('password.request') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-gray-700 text-xs font-bold hover:border-[#E85C24]">
                    Сброс пароля / вход по email
                </a>
                <a href="{{ route('student.access') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-gray-700 text-xs font-bold hover:border-[#E85C24]">
                    Оплата и доступ
                </a>
            </div>
        </div>
    </div>
@endif
