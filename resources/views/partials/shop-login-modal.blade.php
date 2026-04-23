{{-- resources/views/partials/shop-login-modal.blade.php --}}
<div x-data="shopLoginModal()"
     x-show="open"
     x-cloak
     x-on:open-shop-login.window="openModal()"
     x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
     x-on:click.self="open = false">

    <div class="bg-[#111622] text-white rounded-2xl shadow-2xl w-full max-w-md border border-[#1F2636] overflow-hidden"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        <div class="flex items-center justify-between px-6 py-4 border-b border-[#1F2636]">
            <h3 class="text-lg font-bold">Вход в аккаунт</h3>
            <button type="button" x-on:click="open = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-[#1F2636] transition-colors">
                <i class="fas fa-times text-slate-400"></i>
            </button>
        </div>

        <form x-on:submit.prevent="submit" class="p-6 space-y-4">
            <p class="text-sm text-slate-400 leading-relaxed">
    Войдите, чтобы увидеть купленные курсы и персональные скидки.<br>
    <span class="text-red-500 font-bold">ВАЖНО!</span> Первый раз авторизоваться через куратора, сообщив актуальный имейл, что бы мы могли вас идентифицировать и избежать путаницы с доступами.
</p>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Email</label>
                <input type="email" x-ref="emailInput" x-model="form.email" required autocomplete="email"
                       placeholder="you@example.com"
                       class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] py-3 px-4 transition">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Пароль</label>
                <input type="password" x-model="form.password" required autocomplete="current-password"
                       placeholder="••••••••"
                       class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] py-3 px-4 transition">
            </div>

            <template x-if="error">
                <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3"
                     x-text="error"></div>
            </template>

            <label class="flex items-center gap-2 text-sm text-slate-400 cursor-pointer select-none">
                <input type="checkbox" x-model="form.remember"
                       class="rounded border-[#1F2636] bg-[#0A0D14] text-[#E85C24] focus:ring-[#E85C24]">
                Запомнить меня
            </label>

            <button type="submit" x-bind:disabled="loading"
                    class="w-full flex justify-center items-center py-3.5 px-4 bg-[#E85C24] hover:bg-[#d64e1c] disabled:opacity-60 disabled:cursor-not-allowed text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-[#E85C24]/20">
                <span x-show="!loading">Войти</span>
                <span x-show="loading" x-cloak>
                    <i class="fas fa-spinner fa-spin mr-2"></i> Входим...
                </span>
            </button>

            <div class="text-center text-xs text-slate-500 pt-2">
                Забыли пароль? Напишите в
                <a href="https://t.me/rusamskrtam" target="_blank" class="text-[#38BDF8] hover:underline">
                    Куратору Тг</a> или <a href="https://vk.me/event89658969" target="_blank" class="text-[#38BDF8] hover:underline">
                    Куратору Вк</a>
            </div>
        </form>
    </div>
</div>

<script>
    function shopLoginModal() {
    return {
        open: false,
        loading: false,
        error: null,
        form: { email: '', password: '', remember: false },

        openModal() {
            this.open = true;
            this.$nextTick(() => {
                if (this.$refs.emailInput) {
                    this.$refs.emailInput.focus();
                }
            });
        },

        async submit() {
            this.loading = true;
            this.error = null;

            try {
                const response = await fetch(@json(route('shop.login')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(this.form),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data.success) {
                    this.error = data.message
                        || (data.errors && Object.values(data.errors)[0]?.[0])
                        || 'Не удалось войти. Проверьте данные.';
                    this.loading = false;
                    return;
                }

                window.location.reload();
            } catch (e) {
                console.error(e);
                this.error = 'Ошибка сети. Попробуйте еще раз.';
                this.loading = false;
            }
        },
    };
}
</script>