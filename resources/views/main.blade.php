<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Общество ревнителей санскрита</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🕉️</text></svg>">
    
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ config('app.url') }}">
    <meta property="og:title" content="Общество ревнителей санскрита">
    <meta property="og:description" content="Платформа для глубокого изучения языка, философии и текстов.">
    <meta property="og:image" content="{{ asset('images/og-main-preview.jpg') }}">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Общество ревнителей санскрита">
    <meta name="twitter:description" content="Платформа для глубокого изучения языка, философии и текстов.">
    <meta name="twitter:image" content="{{ asset('images/og-main-preview.jpg') }}">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Montserrat', sans-serif; }
        
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        .animate-blob { animation: blob 7s infinite; }
        .animation-delay-2000 { animation-delay: 2s; }
        
        /* Обрезка текста для карточек */
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;  
            overflow: hidden;
        }

        /* Адаптация стандартной пагинации Laravel под темную тему */
        nav[role="navigation"] { border: none !important; box-shadow: none !important; }
        nav[role="navigation"] p { color: #9ca3af !important; }
        nav[role="navigation"] a { background-color: #1f2937 !important; border-color: #374151 !important; color: #fff !important; }
        nav[role="navigation"] a:hover { background-color: #374151 !important; color: #E85C24 !important; }
        nav[role="navigation"] span[aria-current="page"] span { background-color: #E85C24 !important; border-color: #E85C24 !important; color: #fff !important; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen relative overflow-x-hidden pt-12 pb-20">

    <div class="fixed top-0 left-0 w-96 h-96 bg-[#E85C24] rounded-full mix-blend-screen filter blur-3xl opacity-20 animate-blob pointer-events-none"></div>
    <div class="fixed bottom-0 right-0 w-96 h-96 bg-purple-600 rounded-full mix-blend-screen filter blur-3xl opacity-20 animate-blob animation-delay-2000 pointer-events-none"></div>

    <div class="container mx-auto px-4 relative z-10 max-w-7xl">
        
        <div class="text-center mb-16">
    <h1 class="text-5xl md:text-6xl font-extrabold mb-4 tracking-tight">
        Общество ревнителей санскрита
    </h1>
    
    <div class="w-24 h-1 bg-[#E85C24] mx-auto mb-6 rounded-full"></div>
    
    <p class="text-xl text-gray-300 max-w-2xl mx-auto mb-10">
        Платформа для глубокого изучения языка, философии и текстов. Выберите курс для начала обучения.
    </p>
    
    <h2 class="text-3xl md:text-4xl font-bold">
        <span class="text-[#E85C24]">Наши курсы:</span>
    </h2>
</div>

        @if($landings->count() > 0)
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 md:gap-8">
                @foreach($landings as $landing)
                    <a href="{{ url('/' . $landing->slug) }}" class="relative group block h-full transition-all duration-300 hover:-translate-y-1">
                        
                        @if($landing->webinar_label)
                            <span class="absolute top-5 -left-3 sm:-left-4 z-20 bg-[#E85C24] text-white text-[10px] sm:text-xs uppercase tracking-wider font-bold px-3 py-1.5 rounded-md shadow-[0_5px_15px_rgba(232,92,36,0.5)]">
                                {{ $landing->webinar_label }}
                            </span>
                        @endif

                        <div class="flex flex-col sm:flex-row items-stretch h-full w-full bg-[#161b28] border border-gray-700/60 rounded-2xl overflow-hidden group-hover:border-[#E85C24]/60 group-hover:shadow-[0_0_25px_rgba(232,92,36,0.15)] transition-all duration-300">
                            
                            <div class="relative w-full sm:w-40 shrink-0 bg-gray-800 h-56 sm:h-auto overflow-hidden">
                                @if($landing->showcase_image)
                                    <img src="{{ asset('storage/' . $landing->showcase_image) }}" alt="{{ $landing->title }}" class="absolute inset-0 w-full h-full object-cover object-top group-hover:scale-105 transition-transform duration-700 ease-in-out">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center bg-[#1a2133] text-gray-500">
                                        <svg class="w-10 h-10 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                @endif
                                <div class="hidden sm:block absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-[#161b28] to-transparent"></div>
                            </div>

                            <div class="p-5 flex flex-col flex-grow relative z-10 bg-[#161b28]">
                                @if($landing->instructor_name)
                                    <p class="text-[#2AABEE] text-xs font-bold uppercase tracking-wider mb-1.5 pl-1 sm:pl-0">
                                        {{ $landing->instructor_name }}
                                    </p>
                                @endif
                                <h3 class="text-lg md:text-xl font-bold text-white mb-2 group-hover:text-[#E85C24] transition-colors leading-tight pl-1 sm:pl-0">
                                    {{ $landing->title }}
                                </h3>
                                <p class="text-gray-400 text-sm line-clamp-3 mb-4 flex-grow pl-1 sm:pl-0">
                                    {{ $landing->showcase_description ?? 'Узнать подробнее о программе курса, расписании и стоимости.' }}
                                </p>
                                <div class="mt-auto pt-3 border-t border-gray-700/50 pl-1 sm:pl-0">
                                    <span class="inline-flex items-center text-sm font-bold text-white group-hover:text-[#E85C24] transition-colors">
                                        {{ $landing->button_text ?? 'Записаться' }}
                                        <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-12 flex justify-center">
                {{ $landings->links() }}
            </div>
            
        @else
            <div class="text-center py-20 bg-gray-800/50 rounded-2xl border border-gray-700">
                <p class="text-gray-400 text-lg">Скоро здесь появятся наши новые курсы!</p>
            </div>
        @endif


        <div class="mt-24 bg-gray-800/40 border border-gray-700/60 rounded-3xl p-8 md:p-12 relative overflow-hidden backdrop-blur-sm shadow-xl">
            <div class="absolute top-0 right-0 -mt-10 -mr-10 w-40 h-40 bg-[#2AABEE] rounded-full mix-blend-screen filter blur-3xl opacity-10"></div>
            <div class="absolute bottom-0 left-0 -mb-10 -ml-10 w-40 h-40 bg-[#E85C24] rounded-full mix-blend-screen filter blur-3xl opacity-10"></div>
            
            <div class="relative z-10 max-w-4xl mx-auto text-center">
                <h2 class="text-3xl md:text-4xl font-extrabold text-white mb-6">О нашей платформе</h2>
                <div class="w-16 h-1 bg-[#2AABEE] mx-auto mb-8 rounded-full"></div>
                
                <div class="text-gray-300 text-base md:text-lg leading-relaxed space-y-6">
                    <p>
                        Все программы Общества ревнителей санскрита носят исключительно просветительский характер. Участие в них не ведёт к присвоению квалификации, профессии или получению документов об образовании.
                    </p>
                    <p>
                        Наша главная цель — популяризация санскрита, знакомство с богатым культурным и философским наследием Индии, а также создание сообщества единомышленников для совместного изучения этого древнего языка.
                    </p>
                    <p>
                        Наши лекторы — это индологи, востоковеды, филологи, философы, йоги с большим практическим опытом.
                    </p>
                    <p>
                        До встречи на занятиях!
                    </p>
                    </div>
            </div>
        </div>
        <div class="mt-20 pt-10 border-t border-gray-800 text-center flex flex-col items-center">
            <a href="https://t.me/+N4xQ6zHFepk5NmYy" target="_blank" class="inline-flex items-center justify-center px-6 py-3 text-sm font-bold text-white rounded-xl transition-all duration-300 hover:scale-105 bg-gradient-to-r from-[#2AABEE] to-[#0088cc] shadow-[0_0_15px_rgba(0,136,204,0.4)]">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 11.944 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.697.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.628 4.476-1.636z"/></svg>
                Следить за новостями в Telegram
            </a>
            <p class="mt-8 text-sm text-gray-600">
                &copy; {{ date('Y') }} Общество ревнителей санскрита. Все права защищены.
            </p>
        </div>

    </div>
</body>
</html>