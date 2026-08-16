{{--
    Карточка клубного членства (H2644): срок, отказ от продления, честный текст
    о том, что именно заканчивается.

    Формулировка ограничена §2.4 спецификации: ссылки на видео unlisted, открытые
    студентом продолжают работать. Поэтому здесь сказано, что заканчивается ПРАВО
    и перестают открываться СТРАНИЦЫ — а не «доступ будет отозван».
    Согласовать любые правки текста с H2645 (лендинг + прайсинг).
--}}
@if(!empty($clubMembership))
<div class="mb-16">
    <h3 class="text-2xl font-extrabold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-id-card text-[#38BDF8] mr-3"></i>Клуб
    </h3>

    <div class="bg-white rounded-2xl shadow-[0_2px_12px_rgba(0,0,0,0.04)] border border-gray-100 p-6">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            @if($clubMembership->renewalCancelled())
                <span class="px-2.5 py-1 bg-amber-50 text-amber-700 text-[10px] font-bold uppercase tracking-widest rounded border border-amber-100">
                    Продление отключено
                </span>
            @else
                <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-widest rounded border border-emerald-100">
                    Активен
                </span>
            @endif
            <span class="text-gray-900 font-bold">
                Оплачен до {{ $clubMembership->ends_at->translatedFormat('d F Y') }}
            </span>
        </div>

        @if($clubMembership->renewalCancelled())
            <p class="text-gray-600 text-sm mb-4">
                Мы не будем напоминать о продлении. Всё, что входит в клуб, работает
                до {{ $clubMembership->ends_at->translatedFormat('d F Y') }} — можно спокойно досмотреть.
            </p>
        @else
            <p class="text-gray-600 text-sm mb-4">
                Продление ручное: клуб не списывает деньги с карты сам. Чтобы продлить,
                оплатите тариф ещё раз — новый срок прибавится к текущему, а не начнётся заново.
            </p>
        @endif

        <div class="bg-gray-50 rounded-xl p-4 mb-4">
            <p class="text-gray-900 text-sm font-bold mb-2">Что происходит, когда клуб заканчивается</p>
            <p class="text-gray-600 text-sm leading-relaxed">
                Заканчивается <strong>право доступа</strong>: клубные страницы записей перестают
                открываться в кабинете, новые записи в полку не добавляются. Уроки, которые вы уже
                открыли, мы у вас не отбираем — ссылки на видео продолжают работать. Купленные
                курсы, домашние задания и сертификаты клуб не затрагивает: они остаются вашими
                независимо от подписки.
            </p>
        </div>

        @if(config('features.membership_cancellation'))
            @if($clubMembership->renewalCancelled())
                <form method="POST" action="{{ route('student.membership.resume') }}">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2.5 bg-gray-900 text-white text-sm font-bold rounded-xl hover:bg-black transition-all duration-300">
                        Включить продление обратно
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('student.membership.cancel') }}"
                      onsubmit="return confirm('Отключить продление? Клуб продолжит работать до {{ $clubMembership->ends_at->translatedFormat('d.m.Y') }}.');">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2.5 bg-gray-50 text-gray-900 text-sm font-bold rounded-xl border border-gray-200 hover:bg-gray-100 transition-all duration-300">
                        Не продлевать
                    </button>
                </form>
            @endif
        @endif
    </div>
</div>
@endif

{{-- Полка записей клуба: курсы, открытые членством, а не покупкой. --}}
@if(!empty($clubShelf) && $clubShelf->isNotEmpty())
<div class="mb-16">
    <h3 class="text-2xl font-extrabold text-gray-900 mb-6 flex items-center">
        <i class="fas fa-box-archive text-[#38BDF8] mr-3"></i>Записи клуба
        <div class="ml-3 px-3 py-1 bg-sky-50 text-sky-600 text-sm font-bold rounded-full border border-sky-100">{{ $clubShelf->count() }}</div>
    </h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($clubShelf as $clubCourse)
            <div class="bg-white rounded-2xl shadow-[0_2px_12px_rgba(0,0,0,0.04)] border border-gray-100 hover:border-[#38BDF8]/40 hover:shadow-[0_15px_35px_rgba(56,189,248,0.08)] hover:-translate-y-1 transition-all duration-300 flex flex-col p-6">
                @php($clubScope = app(\App\Services\Membership\ClubEntitlement::class)->scopeLabelFor($clubCourse))
                <span class="self-start px-2.5 py-1 bg-sky-50 text-sky-600 text-[10px] font-bold uppercase tracking-widest rounded border border-sky-100 mb-3">
                    {{-- H2886: объём назван на карточке. Курс, от которого клуб даёт один
                         блок, не должен читаться как курс целиком — иначе полка обещает
                         больше, чем открывает, и разбираться будет уже оплативший. --}}
                    По клубу@if($clubScope) · {{ $clubScope }}@endif
                </span>
                <h4 class="text-lg font-bold text-gray-900 mb-4 leading-snug line-clamp-2">{{ $clubCourse->title }}</h4>
                <a href="{{ route('student.course', $clubCourse->slug) }}"
                   class="mt-auto flex items-center justify-center w-full px-4 py-2.5 bg-gray-50 text-gray-900 text-sm font-bold rounded-xl hover:bg-[#38BDF8] hover:text-white transition-all duration-300">
                    <span>Смотреть записи</span>
                    <i class="fas fa-arrow-right ml-2 text-xs opacity-50"></i>
                </a>
            </div>
        @endforeach
    </div>
</div>
@endif
