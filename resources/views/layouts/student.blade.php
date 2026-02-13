<!DOCTYPE html>
<html lang="ru" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Обучение') | Sanskrit LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full">
    <div class="min-h-full">
        <div class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
            <div class="flex flex-col flex-grow bg-indigo-700 pt-5 pb-4 overflow-y-auto">
                <div class="flex items-center flex-shrink-0 px-4 mb-8">
                    <span class="text-white text-2xl font-bold tracking-wider">SANSKRIT<span class="text-indigo-300">LMS</span></span>
                </div>
                <nav class="mt-5 flex-1 px-2 space-y-1">
                    <a href="{{ route('student.dashboard') }}" class="bg-indigo-800 text-white group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-th-large mr-3 text-indigo-300"></i> Мои курсы
                    </a>
                    <a href="#" class="text-indigo-100 hover:bg-indigo-600 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-graduation-cap mr-3 text-indigo-300"></i> Словарь
                    </a>
                </nav>
            </div>
        </div>

        <div class="lg:pl-64 flex flex-col flex-1">
            <div class="sticky top-0 z-10 flex-shrink-0 flex h-16 bg-white shadow">
                <div class="flex-1 px-4 flex justify-between">
                    <div class="flex-1 flex items-center">
                        <h1 class="text-xl font-semibold text-gray-900">@yield('header')</h1>
                    </div>
                    <div class="ml-4 flex items-center md:ml-6">
                        <span class="mr-4 text-sm text-gray-500">{{ Auth::user()->name }}</span>
                        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                            @csrf
                            <button type="submit" class="text-gray-400 hover:text-red-500 transition">
                                <i class="fas fa-sign-out-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <main class="py-6">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('mobile-menu-button');
        const sidebar = document.querySelector('.lg\\:flex-col'); // Находим наш сайдбар

        if (btn && sidebar) {
            btn.addEventListener('click', () => {
                sidebar.classList.toggle('hidden');
                sidebar.classList.toggle('fixed');
                sidebar.classList.toggle('z-50');
                sidebar.classList.toggle('w-full');
            });
        }
    });
</script>

</body>
</html>
