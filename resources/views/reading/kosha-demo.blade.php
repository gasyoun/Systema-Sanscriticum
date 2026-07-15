@extends('layouts.slovar')

@section('title', $pack['title'].' — чтение с разбором | Общество ревнителей санскрита')
@section('meta_description', 'Санскритский текст построчно с разбором каждого слова: лемма, морфология, значение.')
@section('robots', 'noindex, follow')

@section('content')
    <header class="text-center mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#E85C24] tracking-tight">{{ $pack['title'] }}</h1>
        <p class="mt-2 text-gray-400 text-sm">{{ $pack['ref'] }} · {{ $pack['text_name'] }} · {{ $pack['source'] }}</p>
        <p class="mt-3 text-gray-500 text-xs">
            <b class="text-gray-300">{{ $pack['stats']['sentences'] }}</b> предложений ·
            <b class="text-gray-300">{{ $pack['stats']['tokens'] }}</b> слов ·
            <b class="text-gray-300">{{ $pack['stats']['linked_tokens'] }}</b> разобрано
            (<b class="text-gray-300">{{ $pack['stats']['link_rate_pct'] }}%</b>)
        </p>
        <p class="mt-4 text-gray-500 text-xs max-w-xl mx-auto">
            Нажмите на слово, чтобы увидеть лемму, форму и значение.
        </p>
    </header>

    <div class="space-y-6 max-w-3xl mx-auto">
        @foreach($pack['sentences'] as $sentence)
            <article class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
                <div class="text-xs uppercase tracking-widest text-gray-500 mb-2">{{ $sentence['locus'] }}</div>
                <div class="deva text-xl text-white mb-3 leading-relaxed">
                    @foreach($sentence['tokens'] as $token)
                        <details class="inline-block align-baseline mr-1 mb-1 group">
                            <summary class="inline-block cursor-pointer px-1.5 py-0.5 rounded-md bg-gray-800/70 text-gray-100 hover:bg-gray-700 hover:text-[#E85C24] transition-colors marker:content-none [&::-webkit-details-marker]:hidden">{{ $token['form'] }}</summary>
                            <div class="mt-1 mb-2 text-xs font-sans normal-case tracking-normal text-gray-400 bg-[#0f1420] rounded-lg px-3 py-2 inline-block">
                                <span class="text-gray-300 font-semibold" lang="sa-Latn">{{ $token['lemma'] }}</span>
                                @if(!empty($token['morph']))
                                    <span class="text-gray-500"> · {{ $token['morph'] }}</span>
                                @endif
                                @if(!empty($token['gloss']))
                                    <div class="mt-1 text-gray-400">{{ $token['gloss'] }}</div>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
                <div class="text-sm italic text-gray-500" lang="sa-Latn">{{ $sentence['text'] }}</div>
            </article>
        @endforeach
    </div>

    <div class="max-w-3xl mx-auto mt-14 pt-8 border-t border-gray-800 text-sm text-gray-500">
        Текст и разбор: <a href="https://www.sanskrit-linguistics.org/dcs/" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline">Digital Corpus of Sanskrit</a> (CC BY 4.0), через
        <a href="https://github.com/gasyoun/kosha" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline">kosha</a>.
    </div>
@endsection
