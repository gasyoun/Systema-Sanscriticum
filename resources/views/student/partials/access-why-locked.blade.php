{{-- H2386: «Почему закрыто?» — findings from AccessDiagnosticsService.
     Shown only when features.access_self_service is ON and $findings is non-empty.
     Actions: materialize (POST), connect bot / reset password / pay / check (links). --}}
@php
    $findings = $findings ?? [];
    $enabled = $enabled ?? (bool) config('features.access_self_service', false);
@endphp
@if ($enabled && ! empty($findings))
    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50/80 px-3 py-2.5 text-left"
         onclick="event.preventDefault(); event.stopPropagation();"
         onmousedown="event.stopPropagation();">
        <p class="text-[11px] font-extrabold uppercase tracking-widest text-amber-800 mb-1.5">
            <i class="fas fa-stethoscope mr-1"></i> Почему закрыто?
        </p>
        <ul class="space-y-2">
            @foreach ($findings as $finding)
                @if (($finding['code'] ?? '') === 'no_finding')
                    @continue
                @endif
                <li class="text-xs text-amber-950/90 leading-snug">
                    <span>{{ $finding['message'] ?? '' }}</span>
                    @php $action = $finding['action'] ?? null; @endphp
                    @if ($action === 'materialize' && ! empty($finding['course_slug']))
                        <form method="POST"
                              action="{{ route('student.access.materialize', $finding['course_slug']) }}"
                              class="inline-block mt-1.5">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#E85C24] hover:bg-[#d04a15] text-white text-[11px] font-bold">
                                {{ $finding['action_label'] ?? 'Открыть оплаченные блоки' }}
                            </button>
                        </form>
                    @elseif ($action === 'connect_bot')
                        <a href="{{ route('telegram.connect') }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 mt-1.5 px-2.5 py-1 rounded-lg bg-[#0088cc] hover:bg-[#0077b5] text-white text-[11px] font-bold">
                            {{ $finding['action_label'] ?? 'Подключить бота' }}
                        </a>
                    @elseif ($action === 'reset_password')
                        <a href="{{ route('password.request') }}"
                           class="inline-flex items-center gap-1.5 mt-1.5 px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] font-bold">
                            {{ $finding['action_label'] ?? 'Сбросить пароль' }}
                        </a>
                    @elseif ($action === 'check_payment' || $action === 'pay_debt')
                        <a href="{{ route('student.access') }}"
                           class="inline-flex items-center gap-1.5 mt-1.5 px-2.5 py-1 rounded-lg border border-amber-300 bg-white text-amber-900 text-[11px] font-bold hover:border-[#E85C24]">
                            {{ $finding['action_label'] ?? 'К оплате' }}
                        </a>
                    @elseif ($action === 'call_curator')
                        <a href="{{ route('student.dashboard') }}#support"
                           class="inline-flex items-center gap-1.5 mt-1.5 px-2.5 py-1 rounded-lg border border-gray-300 bg-white text-gray-700 text-[11px] font-bold">
                            {{ $finding['action_label'] ?? 'Позвать куратора' }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
