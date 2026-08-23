@extends('layouts.shop')

@section('title', 'Благодарности меценатам')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold text-white mb-4">Благодарности меценатам</h1>
    <p class="text-slate-300 mb-8">
        Имена меценатов Института исследования санскрита, согласившихся на публикацию.
        Сумма пожертвования указывается только там, где сам меценат попросил её показать.
        Поддержать можно на странице <a href="{{ route('institute.mecenaty') }}" class="underline">«Меценаты Института»</a>.
    </p>

    @if ($donations->isEmpty())
        <p class="text-slate-400">Пока список пуст.</p>
    @else
        <ul class="space-y-3">
            @foreach ($donations as $donation)
                <li class="rounded-xl border border-slate-700 p-4">
                    <span class="text-white font-semibold">{{ $donation->donor_name ?? 'Меценат' }}</span>
                    @if ($donation->show_amount)
                        <span class="text-slate-300">— {{ number_format((float) $donation->amount, 0, '', ' ') }} ₽</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
