@props([
    'url' => '',
    'text' => '',
])

<div class="mt-5 bg-[#111622] border border-[#1F2636] rounded-3xl p-6 md:p-8">
    <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-3">Поделиться</div>
    <div class="flex flex-wrap items-center gap-3">
        <a href="https://t.me/share/url?{{ http_build_query(['url' => $url, 'text' => $text]) }}"
           target="_blank"
           rel="noopener"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#1F2636] border border-[#2A3348] text-sm font-semibold text-slate-200 hover:border-brand hover:text-white transition-colors">
            <i class="fab fa-telegram text-sky-400"></i> Telegram
        </a>
        <a href="https://vk.com/share.php?url={{ urlencode($url) }}"
           target="_blank"
           rel="noopener"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#1F2636] border border-[#2A3348] text-sm font-semibold text-slate-200 hover:border-brand hover:text-white transition-colors">
            <i class="fab fa-vk text-blue-400"></i> ВКонтакте
        </a>
        <button type="button"
                data-copy-url="{{ $url }}"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#1F2636] border border-[#2A3348] text-sm font-semibold text-slate-200 hover:border-brand hover:text-white transition-colors">
            <i class="fas fa-link"></i> <span class="js-copy-label">Скопировать ссылку</span>
        </button>
    </div>
</div>

<script>
document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var label = btn.querySelector('.js-copy-label');
        if (!label || !navigator.clipboard || !navigator.clipboard.writeText) {
            return;
        }
        navigator.clipboard.writeText(btn.getAttribute('data-copy-url')).then(function () {
            var original = label.textContent;
            label.textContent = 'Скопировано';
            setTimeout(function () {
                label.textContent = original;
            }, 2000);
        });
    });
});
</script>
