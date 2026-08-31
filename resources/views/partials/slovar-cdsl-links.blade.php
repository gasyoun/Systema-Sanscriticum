{{-- H3762: Cologne CDSL link-out (server-side URLs, no client fetch). Пусто, когда SLP1-ключ не разрешим. --}}
@if(!empty($cdslLinks))
    <section class="max-w-3xl mx-auto mt-6 text-sm text-gray-400">
        <span class="font-bold text-gray-500 uppercase tracking-widest text-xs">В словарях CDSL (Кёльн):</span>
        @foreach($cdslLinks as $link)
            <a href="{{ $link['url'] }}" rel="nofollow noopener" target="_blank"
               class="text-[#2AABEE] hover:underline ml-2">{{ $link['label'] }}</a>
        @endforeach
    </section>
@endif
