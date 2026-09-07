@extends('layouts.shop')

@section('title', $definition['title'])

@section('content')
<div class="container mx-auto px-4 py-12 md:py-20 flex justify-center">
    <div class="w-full max-w-2xl">
        @if($done)
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-emerald-500/10 border-b border-emerald-500/20 px-6 py-8 text-center">
                    <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30"><i class="fas fa-check text-2xl"></i></div>
                    <h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Спасибо — ответ получен</h1>
                    <p class="mt-2 text-sm text-slate-400">Он правда повлияет на то, как устроены курсы.</p>
                </div>
                <div class="px-6 py-6 text-center">
                    @if($definition['reward_enabled'])
                        <p class="text-sm text-slate-400">Награду («прана 500 ₽» или бесплатное вводное) начислим по указанному контакту — обычно в течение дня.</p>
                    @else
                        <p class="text-sm text-slate-400">Расписание и запись на занятия доступны на <a href="{{ route('shop.index') }}" class="text-brand hover:underline">samskrte.ru</a>.</p>
                    @endif
                </div>
            </div>
        @else
            @php
                $pageDefinitions = $definition['pages'] ?? [['title' => null, 'intro' => null]];
                $pageCount = count($pageDefinitions);
                $firstErrorPage = 1;
                foreach ($definition['questions'] as $question) {
                    if ($errors->has($question['id'])) { $firstErrorPage = $question['page'] ?? 1; break; }
                }
            @endphp
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl" data-survey-pages="{{ $pageCount }}" data-start-page="{{ $firstErrorPage }}">
                <div class="px-6 py-6 border-b border-[#1F2636] text-center">
                    <h1 class="text-xl md:text-2xl font-extrabold text-white">{{ $definition['title'] }}</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ $definition['intro'] }}</p>
                    @if($pageCount > 1)
                        <div class="mt-5" aria-live="polite">
                            <div class="flex justify-between text-xs font-bold text-slate-400"><span data-page-label>Страница 1 из {{ $pageCount }}</span><span data-page-percent>{{ (int) round(100 / $pageCount) }}%</span></div>
                            <div class="mt-2 h-1.5 rounded-full bg-[#0A0D14] overflow-hidden"><div data-page-progress class="h-full bg-brand transition-all duration-300" style="width: {{ 100 / $pageCount }}%"></div></div>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('survey.store', $slug) }}" class="p-6 md:p-8" data-survey-form novalidate>
                    @csrf
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;height:0;width:0;">

                    @foreach($pageDefinitions as $pageIndex => $pageDefinition)
                        @php $pageNumber = $pageIndex + 1; @endphp
                        <section data-survey-page="{{ $pageNumber }}" class="space-y-6" @if($pageNumber !== $firstErrorPage) hidden @endif>
                            @if($pageCount > 1)
                                <div class="pb-1">
                                    <h2 class="text-lg font-extrabold text-white">{{ $pageDefinition['title'] }}</h2>
                                    @if(filled($pageDefinition['intro'] ?? null))<p class="mt-1 text-sm text-slate-400">{{ $pageDefinition['intro'] }}</p>@endif
                                </div>
                            @endif

                            @foreach($definition['questions'] as $question)
                                @continue(($question['page'] ?? 1) !== $pageNumber)
                                @php $old = old($question['id']); @endphp
                                <div data-question>
                                    <label class="block text-sm font-bold text-slate-200 mb-2">{{ $question['label'] }} @if(($question['required'] ?? false))<span class="text-brand">*</span>@endif</label>
                                    @if($question['type'] === 'radio')
                                        <div class="space-y-1.5">
                                            @foreach($question['options'] as $option)
                                                <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors">
                                                    <input type="radio" name="{{ $question['id'] }}" value="{{ $option }}" @checked($old === $option) @if($question['required'] ?? false) required @endif class="mt-0.5 accent-orange-600 shrink-0">
                                                    <span class="text-sm text-slate-300">{{ $option }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @elseif($question['type'] === 'checkboxes')
                                        <div class="space-y-1.5">
                                            @foreach($question['options'] as $option)
                                                <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors">
                                                    <input type="checkbox" name="{{ $question['id'] }}[]" value="{{ $option }}" @checked(is_array($old) && in_array($option, $old)) class="mt-0.5 accent-orange-600 shrink-0">
                                                    <span class="text-sm text-slate-300">{{ $option }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @elseif($question['type'] === 'scale')
                                        @php $smin = $question['min'] ?? 1; $smax = $question['max'] ?? 5; @endphp
                                        <div class="flex flex-wrap gap-2">
                                            @foreach(range($smin, $smax) as $n)
                                                <label class="cursor-pointer"><input type="radio" name="{{ $question['id'] }}" value="{{ $n }}" @checked($old == $n) @if($question['required'] ?? false) required @endif class="peer sr-only"><span class="inline-flex w-10 h-10 items-center justify-center rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] text-sm font-bold text-slate-300 peer-checked:bg-brand peer-checked:text-white peer-checked:border-brand transition-colors">{{ $n }}</span></label>
                                            @endforeach
                                            <span class="text-xs text-slate-500 self-center ml-2">{{ $smin }} → {{ $smax }}</span>
                                        </div>
                                    @elseif($question['type'] === 'textarea')
                                        <textarea name="{{ $question['id'] }}" rows="4" maxlength="2000" @if($question['required'] ?? false) required @endif class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">{{ $old }}</textarea>
                                    @else
                                        <input type="text" name="{{ $question['id'] }}" value="{{ $old }}" maxlength="200" @if($question['required'] ?? false) required @endif @if(($question['numeric'] ?? false)) inputmode="numeric" @endif class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">
                                    @endif
                                    @error($question['id'])<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>
                            @endforeach

                            @if($pageNumber === $pageCount && $definition['reward_enabled'])
                                <div class="pt-2 border-t border-[#1F2636] space-y-3">
                                    <label class="block text-sm font-bold text-slate-200">Благодарность за ответы <span class="text-brand">*</span></label>
                                    <div class="space-y-1.5">
                                        @foreach(['prana' => 'Прана на 500 ₽', 'intro' => 'Бесплатное вводное занятие', 'none' => 'Без награды'] as $value => $label)
                                            <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors"><input type="radio" name="reward_choice" value="{{ $value }}" @checked(old('reward_choice', 'prana') === $value) required class="mt-0.5 accent-orange-600 shrink-0"><span class="text-sm text-slate-300">{{ $label }}</span></label>
                                        @endforeach
                                    </div>
                                    <div><label class="block text-sm font-bold text-slate-200 mb-2">Куда начислить (email или @telegram) <span class="text-brand">*</span></label><input type="text" name="contact" value="{{ old('contact', $auth_email ?? '') }}" maxlength="200" placeholder="you@mail.ru или @username" class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">@error('contact')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror</div>
                                </div>
                            @endif

                            <div class="pt-3 flex gap-3">
                                @if($pageNumber > 1)<button type="button" data-page-back class="w-1/3 py-3 rounded-xl border border-[#1F2636] hover:border-slate-500 text-slate-200 font-bold transition-colors">Назад</button>@endif
                                @if($pageNumber < $pageCount)
                                    <button type="button" data-page-next class="flex-1 py-3 rounded-xl bg-brand hover:bg-orange-700 text-white font-extrabold transition-colors">Далее</button>
                                @else
                                    <button type="submit" class="flex-1 py-3 rounded-xl bg-brand hover:bg-orange-700 text-white font-extrabold transition-colors">Отправить ответы</button>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </form>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
@if(!$done)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const shell = document.querySelector('[data-survey-pages]');
    const form = document.querySelector('[data-survey-form]');
    if (!shell || !form) return;
    const pages = [...form.querySelectorAll('[data-survey-page]')];
    if (pages.length < 2) return;
    let current = Math.max(0, Math.min(pages.length - 1, Number(shell.dataset.startPage || 1) - 1));
    const show = (index) => {
        current = index;
        pages.forEach((page, i) => page.hidden = i !== current);
        const percent = Math.round(((current + 1) / pages.length) * 100);
        shell.querySelector('[data-page-label]').textContent = `Страница ${current + 1} из ${pages.length}`;
        shell.querySelector('[data-page-percent]').textContent = `${percent}%`;
        shell.querySelector('[data-page-progress]').style.width = `${percent}%`;
        shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-page-back]')) show(current - 1);
        if (event.target.closest('[data-page-next]')) {
            const invalid = pages[current].querySelector(':invalid');
            if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
            show(current + 1);
        }
    });
    form.addEventListener('submit', (event) => {
        const invalid = form.querySelector(':invalid');
        if (!invalid) return;
        event.preventDefault();
        const invalidPage = pages.findIndex(page => page.contains(invalid));
        if (invalidPage >= 0) show(invalidPage);
        invalid.reportValidity();
        invalid.focus();
    });
    show(current);
});
</script>
@endif
@endpush
