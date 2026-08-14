@if(session('hindi_srs_status'))
    @php
        $status = session('hindi_srs_status');
        $word = session('hindi_srs_word');
        $added = (int) session('hindi_srs_count', 0);
        $ok = in_array($status, ['added', 'added_batch'], true);
    @endphp
    <div class="mb-6 rounded-2xl border px-5 py-4 text-sm {{ $ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-gray-200 bg-gray-50 text-gray-700' }}"
         data-testid="hindi-srs-flash"
         data-status="{{ $status }}">
        @if($status === 'added')
            Слово <b lang="hi">{{ $word }}</b> добавлено в вашу колоду «Мой хинди».
        @elseif($status === 'added_batch')
            В колоду «Мой хинди» добавлено слов: {{ $added }}.
        @elseif($status === 'duplicate')
            @if($word)
                Слово <b lang="hi">{{ $word }}</b> уже есть в вашей колоде «Мой хинди».
            @else
                Эти слова уже есть в вашей колоде «Мой хинди».
            @endif
        @elseif($status === 'no_items')
            Из расшифровки этого занятия не получилось собрать слова — карточку не создаём.
        @else
            Для этого задания нет слова — карточку не создаём.
        @endif
        @if(config('srs.enabled'))
            <a href="{{ route('student.srs.deck', \App\Support\HindiMySrsDeck::DECK_SLUG) }}"
               class="ml-2 font-extrabold text-brand hover:underline"
               data-testid="hindi-srs-open-deck">Открыть колоду</a>
        @endif
    </div>
@endif
