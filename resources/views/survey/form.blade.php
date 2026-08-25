@extends('layouts.shop')

@section('title', $definition['title'])

@section('content')
<div class="container mx-auto px-4 py-12 md:py-20 flex justify-center">
    <div class="w-full max-w-xl">

        @if($done)
            {{-- ═══ СПАСИБО ═══ --}}
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-emerald-500/10 border-b border-emerald-500/20 px-6 py-8 text-center">
                    <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30">
                        <i class="fas fa-check text-2xl"></i>
                    </div>
                    <h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Спасибо — ответ получен</h1>
                    <p class="mt-2 text-sm text-slate-400">Он правда повлияет на то, как устроены курсы.</p>
                </div>
                <div class="px-6 py-6 text-center">
                    @if($definition['reward_enabled'])
                        <p class="text-sm text-slate-400">
                            Награду («прана 500 ₽» или бесплатное вводное) начислим по указанному контакту —
                            обычно в течение дня.
                        </p>
                    @else
                        <p class="text-sm text-slate-400">
                            Хотите попробовать на вкус — приходите на бесплатное вводное занятие:
                            расписание и запись на
                            <a href="{{ route('shop.index') }}" class="text-brand hover:underline">samskrte.ru</a>.
                        </p>
                    @endif
                </div>
            </div>
        @else
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl">
                <div class="px-6 py-6 border-b border-[#1F2636] text-center">
                    <h1 class="text-xl md:text-2xl font-extrabold text-white">{{ $definition['title'] }}</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ $definition['intro'] }}</p>
                </div>

                <form method="POST" action="{{ route('survey.store', $slug) }}" class="p-6 md:p-8 space-y-6">
                    @csrf

                    {{-- Ханипот для ботов: скрытое поле, люди его не видят и не заполняют --}}
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                           aria-hidden="true" style="position:absolute;left:-9999px;height:0;width:0;">

                    @foreach($definition['questions'] as $question)
                        @php $old = old($question['id']); @endphp
                        <div>
                            <label class="block text-sm font-bold text-slate-200 mb-2">
                                {{ $question['label'] }}
                                @if(($question['required'] ?? false))<span class="text-brand">*</span>@endif
                            </label>

                            @if($question['type'] === 'radio')
                                <div class="space-y-1.5">
                                    @foreach($question['options'] as $option)
                                        <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors">
                                            <input type="radio" name="{{ $question['id'] }}" value="{{ $option }}"
                                                   @checked($old === $option)
                                                   class="mt-0.5 accent-orange-600 shrink-0">
                                            <span class="text-sm text-slate-300">{{ $option }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @elseif($question['type'] === 'checkboxes')
                                <div class="space-y-1.5">
                                    @foreach($question['options'] as $option)
                                        <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors">
                                            <input type="checkbox" name="{{ $question['id'] }}[]" value="{{ $option }}"
                                                   @checked(is_array($old) && in_array($option, $old))
                                                   class="mt-0.5 accent-orange-600 shrink-0">
                                            <span class="text-sm text-slate-300">{{ $option }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @elseif($question['type'] === 'scale')
                                <div class="flex flex-wrap gap-2">
                                    @foreach(range(1, 5) as $n)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="{{ $question['id'] }}" value="{{ $n }}"
                                                   @checked($old == $n) class="peer sr-only">
                                            <span class="inline-flex w-10 h-10 items-center justify-center rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] text-sm font-bold text-slate-300 peer-checked:bg-brand peer-checked:text-white peer-checked:border-brand transition-colors">{{ $n }}</span>
                                        </label>
                                    @endforeach
                                    <span class="text-xs text-slate-500 self-center ml-2">совсем нет → очень</span>
                                </div>
                            @elseif($question['type'] === 'textarea')
                                <textarea name="{{ $question['id'] }}" rows="3" maxlength="2000"
                                          class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">{{ $old }}</textarea>
                            @else
                                <input type="text" name="{{ $question['id'] }}" value="{{ $old }}" maxlength="200"
                                       @if(($question['numeric'] ?? false)) inputmode="numeric" @endif
                                       class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">
                            @endif

                            @error($question['id'])
                                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    @if($definition['reward_enabled'])
                        <div class="pt-2 border-t border-[#1F2636] space-y-3">
                            <label class="block text-sm font-bold text-slate-200">Благодарность за ответы <span class="text-brand">*</span></label>
                            <div class="space-y-1.5">
                                @foreach(['prana' => 'Прана на 500 ₽', 'intro' => 'Бесплатное вводное занятие', 'none' => 'Без награды'] as $value => $label)
                                    <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] cursor-pointer hover:border-slate-500 transition-colors">
                                        <input type="radio" name="reward_choice" value="{{ $value }}"
                                               @checked(old('reward_choice', 'prana') === $value)
                                               class="mt-0.5 accent-orange-600 shrink-0">
                                        <span class="text-sm text-slate-300">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-200 mb-2">
                                    Куда начислить (email или @telegram) <span class="text-brand">*</span>
                                </label>
                                <input type="text" name="contact" value="{{ old('contact') }}" maxlength="200"
                                       placeholder="you@mail.ru или @username"
                                       class="w-full rounded-lg bg-[#0A0D14]/60 border border-[#1F2636] focus:border-brand outline-none px-3 py-2 text-sm text-slate-200">
                                @error('contact')
                                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endif

                    <button type="submit"
                            class="w-full py-3 rounded-xl bg-brand hover:bg-orange-700 text-white font-extrabold transition-colors">
                        Отправить
                    </button>
                </form>
            </div>
        @endif

    </div>
</div>
@endsection
