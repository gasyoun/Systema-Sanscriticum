<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый пароль | ОРС LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 font-sans text-[#101010]">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden relative">
        {{-- Декоративная линия сверху --}}
        <div class="absolute top-0 left-0 w-full h-1.5 bg-[#E85C24]"></div>

        <div class="p-8 pt-10 sm:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full flex items-center justify-center bg-[#E85C24]/10 text-[#E85C24]">
                    <i class="fas fa-lock-open text-2xl"></i>
                </div>
                <h2 class="text-3xl font-extrabold mb-2 text-gray-900">Новый пароль</h2>
                <p class="text-gray-500 text-sm">Придумайте новый пароль для входа</p>
            </div>

            <form action="{{ route('password.update') }}" method="POST" class="space-y-5">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="email">
                        Email адрес
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input type="email" name="email" id="email" required value="{{ old('email', $email) }}"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm"
                            placeholder="student@example.com">
                    </div>
                    @error('email')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password">
                        Новый пароль
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password" id="password" required autofocus
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm"
                            placeholder="Минимум 8 символов">
                    </div>
                    @error('password')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password_confirmation">
                        Повторите пароль
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password_confirmation" id="password_confirmation" required
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm"
                            placeholder="••••••••">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-[#E85C24] hover:bg-[#d04a15] text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-sm uppercase tracking-wider">
                        Сохранить новый пароль
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-gray-50/80 px-8 py-5 border-t border-gray-100 text-center">
            <p class="text-xs text-gray-500">
                <a href="{{ route('login') }}" class="text-[#E85C24] hover:underline font-semibold transition-colors">Вернуться ко входу</a>
            </p>
        </div>
    </div>

</body>
</html>
