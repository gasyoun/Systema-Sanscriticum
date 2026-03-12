<section class="py-16 bg-white">
    <div class="container mx-auto px-4">
        
        {{-- Заголовок блока (если есть) --}}
        @if(!empty($data['title']))
        <div class="text-center mb-10">
            <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">
                {{ $data['title'] }}
            </h2>
            <div class="w-24 h-1.5 bg-[#E85C24] mx-auto mt-4 rounded-full opacity-80"></div>
        </div>
        @endif

        {{-- Само видео --}}
        <div class="relative rounded-2xl overflow-hidden shadow-xl border border-gray-100 max-w-4xl mx-auto bg-black">
            <div class="relative w-full" style="padding-top: 56.25%;"> {{-- Соотношение 16:9 --}}
                <iframe src="{{ $data['video_url'] }}" 
                        class="absolute top-0 left-0 w-full h-full" 
                        frameborder="0" 
                        allow="autoplay; encrypted-media; fullscreen; picture-in-picture;" 
                        allowfullscreen>
                </iframe>
            </div>
        </div>

    </div>
</section>