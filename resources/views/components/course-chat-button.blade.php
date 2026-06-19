@props(['url' => null])

@if(!empty($url))
    <a href="{{ $url }}"
       target="_blank"
       rel="noopener noreferrer"
       title="Чат курса"
       {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 px-4 py-2.5 md:py-3 rounded-xl bg-white text-[#E85C24] border-2 border-[#E85C24] hover:bg-orange-50 text-xs md:text-sm font-extrabold leading-none transition-all shadow-sm hover:shadow-md hover:-translate-y-0.5 uppercase tracking-wide']) }}>
        <i class="fas fa-users text-base"></i>
        <span class="hidden md:inline">Чат курса</span>
    </a>
@endif
