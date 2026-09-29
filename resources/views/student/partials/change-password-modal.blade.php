{{-- Модалка смены пароля — общая для легаси-дашборда и hybrid «Сегодня».
     Открывается событием open-change-password (кнопка «Сменить пароль»);
     при ошибке валидации открывается сама. На hybrid её раньше не было вовсе:
     прод живёт на CABINET_HYBRID, и кнопка пропала из кабинета (29-09-2026). --}}
<div x-data="{ open: false }"
     x-on:open-change-password.window="open = true"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     data-change-password-modal
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
     x-on:click.self="open = false">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden relative"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        <div class="flex items-center justify-between px-6 pt-6 pb-4">
            <h3 class="text-lg font-extrabold text-gray-900">Смена пароля</h3>
            <button type="button" x-on:click="open = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors text-gray-400">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form action="{{ route('student.password.update') }}" method="POST" class="px-6 pb-6 space-y-4">
            @csrf

            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="current_password">Текущий пароль</label>
                <input type="password" name="current_password" id="current_password" required autocomplete="current-password"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                       placeholder="••••••••">
                @error('current_password')
                    <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-400 mt-2 pl-1">
                    Не помните текущий пароль?
                    <a href="{{ route('password.request') }}" class="text-brand font-bold hover:underline">Сбросить по email</a>
                </p>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="new_password">Новый пароль</label>
                <input type="password" name="password" id="new_password" required autocomplete="new-password"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                       placeholder="Минимум 8 символов">
                @error('password')
                    <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password_confirmation">Повторите новый пароль</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                       placeholder="••••••••">
            </div>

            <button type="submit"
                    class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-3 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl text-sm uppercase tracking-wider">
                Сохранить
            </button>
        </form>
    </div>
</div>

@if ($errors->has('current_password') || $errors->has('password'))
    <div x-init="$dispatch('open-change-password')"></div>
@endif
