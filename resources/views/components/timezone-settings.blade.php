@php
    // H4434 — timezone settings card (MG 09-09-2026).
    /** @var \App\Models\User $authUser */
    $authUser = auth()->user();
    $effectiveTz = $authUser->effectiveTimezone();
    $isNonMsk = $authUser->isNonMskTimezone();
    $tzStatus = session('tz_status');
@endphp
<div class="bg-white rounded-2xl border border-gray-100 p-4 md:p-5 mb-6" data-tz-card
     data-current-tz="{{ $effectiveTz ?? 'Europe/Moscow' }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="font-bold text-[#101010] text-sm md:text-base mb-1">
                <i class="far fa-clock text-brand mr-1.5"></i>
                Ваше время
            </p>
            <p class="text-gray-500 text-xs md:text-sm">
                @if($isNonMsk)
                    Занятия идут по московскому времени; мы показываем вам и ваше местное.
                @else
                    Ваше время совпадает с московским — всё показывается как есть.
                @endif
            </p>
        </div>
        <button type="button" onclick="document.getElementById('tz-settings').classList.toggle('hidden')"
                class="text-xs font-bold text-gray-500 hover:text-brand shrink-0">
            Изменить
        </button>
    </div>

    @if($tzStatus)
        <p class="mt-3 text-xs font-semibold text-green-700 bg-green-50 border border-green-100 rounded-xl px-3 py-2">
            {{ $tzStatus }}
        </p>
    @endif

    @if($authUser->tz_override)
        <p class="mt-3 text-xs font-semibold text-orange-700 bg-orange-50 border border-orange-100 rounded-xl px-3 py-2">
            Временное пребывание: {{ $authUser->tz_override }} до
            {{ $authUser->tz_override_until?->format('d.m.Y') }}.
            <form method="POST" action="{{ route('student.timezone.override.clear') }}" class="inline">
                @csrf
                <button type="submit" class="underline">Снять</button>
            </form>
        </p>
    @endif

    <div id="tz-settings" class="hidden mt-4 space-y-4">
        {{-- Ручной селектор --}}
        <form method="POST" action="{{ route('student.timezone.update') }}" class="flex flex-col sm:flex-row gap-2">
            @csrf
            <label class="sr-only" for="tz-select">Часовой пояс</label>
            <select id="tz-select" name="timezone"
                    class="flex-1 text-sm border border-gray-200 rounded-xl px-3 py-2 bg-white text-gray-700">
                @php
                    $commonTzs = [
                        'Europe/Moscow' => 'Москва (МСК)',
                        'Europe/Kaliningrad' => 'Калининград',
                        'Europe/Samara' => 'Самара',
                        'Asia/Yekaterinburg' => 'Екатеринбург',
                        'Asia/Omsk' => 'Омск',
                        'Asia/Novosibirsk' => 'Новосибирск',
                        'Asia/Krasnoyarsk' => 'Красноярск',
                        'Asia/Irkutsk' => 'Иркутск',
                        'Asia/Yakutsk' => 'Якутск',
                        'Asia/Vladivostok' => 'Владивостok',
                        'Asia/Magadan' => 'Магадан',
                        'Asia/Kamchatka' => 'Камчатка',
                        'Europe/Madrid' => 'Мадрид / Испания',
                        'Europe/Berlin' => 'Берлин / Германия',
                        'Europe/Amsterdam' => 'Амстердам / Нидерланды',
                        'Europe/Paris' => 'Париж / Франция',
                        'Europe/Rome' => 'Рим / Италия',
                        'Europe/Riga' => 'Рига / Латвия',
                        'Europe/Vilnius' => 'Вильнюс / Литва',
                        'Europe/Tallinn' => 'Таллин / Эстония',
                        'Europe/Kyiv' => 'Киев',
                        'Europe/Chisinau' => 'Кишинёв',
                        'Europe/Sofia' => 'София',
                        'Europe/Warsaw' => 'Варшава',
                        'Europe/Prague' => 'Прага',
                        'America/Los_Angeles' => 'Лос-Анджелес',
                        'America/New_York' => 'Нью-Йорк',
                        'America/Toronto' => 'Торонто',
                        'Asia/Tbilisi' => 'Тбилиси',
                        'Asia/Yerevan' => 'Ереван',
                        'Asia/Almaty' => 'Алматы',
                        'Asia/Tashkent' => 'Ташкент',
                        'Asia/Kolkata' => 'Дели / Индия',
                        'Asia/Bangkok' => 'Бангкок',
                        'Asia/Jerusalem' => 'Иерусалим',
                        'Asia/Tokyo' => 'Токио',
                    ];
                    $known = $effectiveTz && isset($commonTzs[$effectiveTz]);
                @endphp
                @foreach($commonTzs as $value => $label)
                    <option value="{{ $value }}" @selected($effectiveTz === $value)>{{ $label }}</option>
                @endforeach
                @if($effectiveTz && !$known)
                    <option value="{{ $effectiveTz }}" selected>{{ $effectiveTz }}</option>
                @endif
            </select>
            <button type="submit"
                    class="px-4 py-2 bg-brand hover:bg-[#d6501f] text-white text-xs font-extrabold rounded-xl uppercase tracking-wide">
                Сохранить
            </button>
        </form>

        {{-- Временное пребывание (MG: «уехал на полтора месяца в Индию») --}}
        <form method="POST" action="{{ route('student.timezone.override') }}"
              class="flex flex-col sm:flex-row gap-2 border-t border-gray-100 pt-4">
            @csrf
            <input type="hidden" name="tz_override" value="" data-tz-override-input>
            <label class="sr-only" for="tz-override-country">Временная страна</label>
            <select id="tz-override-country" data-tz-override-select
                    class="flex-1 text-sm border border-gray-200 rounded-xl px-3 py-2 bg-white text-gray-700">
                <option value="">Я временно в другой стране…</option>
                @foreach(['Asia/Kolkata' => 'Индия', 'Asia/Bangkok' => 'Таиланд', 'Asia/Tbilisi' => 'Грузия',
                          'Europe/Madrid' => 'Испания', 'Europe/Berlin' => 'Германия',
                          'America/Los_Angeles' => 'США (Лос-Анджелес)', 'America/New_York' => 'США (Нью-Йорк)',
                          'Asia/Jerusalem' => 'Израиль', 'Europe/Riga' => 'Латвия',
                          'Asia/Almaty' => 'Казахстан', 'Asia/Tashkent' => 'Узбекистан',
                          'Europe/Paris' => 'Франция', 'Europe/Rome' => 'Италия'] as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="tz-override-until">До какой даты</label>
            <input type="date" id="tz-override-until" name="tz_override_until"
                   class="text-sm border border-gray-200 rounded-xl px-3 py-2 bg-white text-gray-700">
            <button type="submit"
                    class="px-4 py-2 bg-gray-800 hover:bg-black text-white text-xs font-extrabold rounded-xl uppercase tracking-wide">
                Задать
            </button>
        </form>

        <p class="text-[11px] text-gray-400">
            Мы определили вашу зону автоматически по часам устройства и не показываем её никому.
            Занятия всегда идут по московскому времени — местное рядом.
        </p>
    </div>
</div>

{{-- Silent device-TZ capture: один POST, если зона устройства не совпадает с сохранённой
     и источник не manual. Потом чип подтверждения (не модалка — MG-паттерн «silent + плашка»). --}}
<script>
(function () {
    var card = document.querySelector('[data-tz-card]');
    if (!card || !navigator.cookieEnabled) return;
    var tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || '').trim();
    if (!tz) return;

    var current = card.getAttribute('data-current-tz') || 'Europe/Moscow';
    if (tz === current) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    fetch('{{ route("student.timezone.device") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token ? token.content : ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ timezone: tz })
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
          if (data && data.ok && !data.kept) {
              // Чип-подтверждение вместо модалки: тихая плашка сверху карточки.
              var chip = document.createElement('p');
              chip.className = 'mt-3 text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2';
              chip.textContent = 'Часы устройства показывают ' + tz + ' — сохранили. Если это ошибка, нажмите «Изменить».';
              card.insertBefore(chip, card.firstChild.nextSibling);
          }
      }).catch(function () {});
})();
</script>