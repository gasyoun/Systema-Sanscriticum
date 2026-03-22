@php
    $title = $block['data']['title'] ?? 'О нашей платформе';
    $content = $lesson->content ?? $lesson->topic ?? $block['data']['content'] ?? '';
@endphp

<div class="py-12 md:py-16 relative z-10 bg-white"> 
    {{-- Убрал ограничитель max-w-7xl, чтобы блок растянулся на всю ширину контейнера, как баннер --}}
    <div class="container mx-auto px-4 relative">
        
        {{-- ГЛАВНЫЙ КОНТЕЙНЕР "СТЕКЛО" --}}
        <div class="relative w-full overflow-hidden bg-white/40 rounded-2xl lg:rounded-[2rem] py-8 px-6 md:py-10 md:px-10 lg:p-12 backdrop-blur-2xl shadow-[0_20px_50px_-10px_rgba(30,41,59,0.08),0_10px_20px_-5px_rgba(30,41,59,0.04)] transition-all duration-300 hover:shadow-[0_30px_70px_-10px_rgba(30,41,59,0.12),0_15px_30px_-5px_rgba(30,41,59,0.06)] group">
            
            {{-- ГЛЯНЦЕВЫЙ БЛИК (Gloss) --}}
            <div class="absolute inset-0 z-0 pointer-events-none opacity-60 group-hover:opacity-100 transition-opacity duration-500"
                 style="background: linear-gradient(135deg, rgba(255,255,255,0.8) 0%, rgba(255,255,255,0) 40%, rgba(255,255,255,0) 60%, rgba(255,255,255,0.5) 100%);">
            </div>

            {{-- ЭФФЕКТ СВЕТЯЩЕГОСЯ КОНТУРА (Edge Light) --}}
            <div class="absolute inset-0 rounded-2xl lg:rounded-[2rem] z-10 pointer-events-none ring-1 ring-inset ring-white/80" 
                 style="box-shadow: inset 0 2px 3px 1px rgba(255,255,255,1), inset 0 -1px 2px 0px rgba(255,255,255,0.3);">
            </div>

            {{-- КОНТЕНТ БЛОКА --}}
            <div class="relative z-20 w-full">
                
                {{-- Заголовок и акцент (ПО ЦЕНТРУ) --}}
                @if($title)
                    <div class="text-center mb-10">
                        <h2 class="text-2xl md:text-3xl lg:text-[32px] font-extrabold text-gray-900 mb-4 tracking-tight leading-tight">{{ $title }}</h2>
                        <div class="w-16 h-1.5 bg-[#E85C24] mx-auto rounded-full shadow-[0_0_15px_rgba(232,92,36,0.4)]"></div>
                    </div>
                @endif
                
                {{-- Текст (ПО ЛЕВОМУ КРАЮ) --}}
                @if($content)
                    <div class="custom-rich-text text-left">
                        {!! $content !!}
                    </div>
                @endif
                
            </div>

        </div>
    </div>
</div>

<style>
    @supports not (backdrop-filter: blur(0px)) {
        .backdrop-blur-2xl {
            background-color: rgba(255, 255, 255, 0.9) !important;
        }
    }
    
    /* ==========================================================
       ЖЕЛЕЗОБЕТОННАЯ ТИПОГРАФИКА ДЛЯ КОНТЕНТА ИЗ АДМИНКИ 
       ========================================================== */
    
    .custom-rich-text {
        color: #374151;
        font-size: 1.05rem;
        line-height: 1.7;
    }
    @media (min-width: 1024px) {
        .custom-rich-text { font-size: 1.125rem; }
    }
    
    .custom-rich-text p {
        margin-bottom: 1.25rem;
    }
    .custom-rich-text p:last-child {
        margin-bottom: 0;
    }

    .custom-rich-text h2, 
    .custom-rich-text h3, 
    .custom-rich-text h4 {
        color: #111827;
        font-weight: 800;
        letter-spacing: -0.025em;
        line-height: 1.3;
    }
    .custom-rich-text h2 { font-size: 1.75rem; margin-top: 2.5rem; margin-bottom: 1rem; }
    .custom-rich-text h3 { font-size: 1.5rem; margin-top: 2rem; margin-bottom: 1rem; }
    .custom-rich-text h4 { font-size: 1.25rem; margin-top: 1.5rem; margin-bottom: 0.75rem; color: #E85C24; }

    .custom-rich-text strong, 
    .custom-rich-text b {
        color: #111827;
        font-weight: 800;
        background-color: #fff7ed;
        padding: 0.1rem 0.25rem;
        border-radius: 0.25rem;
    }

    .custom-rich-text a {
        color: #E85C24;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .custom-rich-text a:hover {
        color: #c2410c;
        text-decoration: underline;
        text-decoration-thickness: 2px;
        text-underline-offset: 4px;
    }

    .custom-rich-text blockquote {
        margin: 1.5rem 0;
        padding: 1.25rem 1.5rem;
        border-left: 4px solid #E85C24;
        background: linear-gradient(90deg, rgba(232, 92, 36, 0.08) 0%, rgba(255, 255, 255, 0) 100%);
        border-top-right-radius: 1rem;
        border-bottom-right-radius: 1rem;
        color: #4b5563;
        font-style: italic;
    }
    .custom-rich-text blockquote p {
        margin-bottom: 0;
    }

    .custom-rich-text ul {
        list-style-type: none;
        padding-left: 0;
        margin-top: 1rem;
        margin-bottom: 1.5rem;
    }
    .custom-rich-text ul li {
        position: relative;
        padding-left: 2rem;
        margin-bottom: 0.75rem;
    }
    .custom-rich-text ul li::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0.375rem;
        width: 1.25rem;
        height: 1.25rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23E85C24'%3E%3Cpath fill-rule='evenodd' d='M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z' clip-rule='evenodd' /%3E%3C/svg%3E");
        background-size: contain;
        background-repeat: no-repeat;
    }

    .custom-rich-text ol {
        padding-left: 1.5rem;
        margin-top: 1rem;
        margin-bottom: 1.5rem;
    }
    .custom-rich-text ol li {
        margin-bottom: 0.75rem;
        padding-left: 0.5rem;
    }
    .custom-rich-text ol li::marker {
        color: #E85C24;
        font-weight: 800;
    }
</style>