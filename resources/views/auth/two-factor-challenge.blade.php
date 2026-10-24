@extends('auth.two-factor-layout')

@section('title', 'Подтверждение входа')

@section('content')
    <h1>Подтвердите вход</h1>
    <p class="muted">Введите код из приложения-аутентификатора. Если приложение недоступно,
        подойдёт один из резервных кодов, которые вы сохранили при подключении.</p>

    @error('code')
        <p class="error" role="alert">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf
        <label for="code">Код из приложения или резервный код</label>
        <input id="code" name="code" value="{{ old('code') }}" inputmode="numeric"
               autocomplete="one-time-code" autofocus required>
        <button type="submit">Войти</button>
    </form>

    <form method="POST" action="{{ route('two-factor.logout') }}" class="actions">
        @csrf
        <button type="submit" class="secondary">Выйти</button>
    </form>
@endsection
