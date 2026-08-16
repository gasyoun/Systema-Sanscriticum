{{-- H2918: lock-listed contextual links to samskrtam.ru. Empty list = no markup. --}}
@php
    $samskrtamLinks = \App\Support\SamskrtamRelated::linksFor($samskrtamKey ?? null);
@endphp
@if($samskrtamLinks !== [])
<section id="samskrtam-related" class="mb-16 lg:mb-20" aria-labelledby="samskrtam-related-heading">
    <h2 id="samskrtam-related-heading" class="text-3xl font-bold text-white mb-6">
        {{ \App\Support\SamskrtamRelated::HEADING }}
    </h2>
    <ul class="space-y-3">
        @foreach($samskrtamLinks as $link)
            <li>
                <a href="{{ $link['url'] }}"
                   class="inline-flex items-center gap-2 text-indigo-400 hover:text-indigo-300 underline underline-offset-2">
                    {{ $link['anchor'] }}
                    <i class="fas fa-external-link-alt text-[10px] opacity-70" aria-hidden="true"></i>
                </a>
            </li>
        @endforeach
    </ul>
</section>
@endif
