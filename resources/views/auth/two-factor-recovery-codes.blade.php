@extends('auth.two-factor-layout')

@section('title', 'Резервные коды')

@section('content')
    <h1>Сохраните резервные коды</h1>
    <p class="muted">Каждый код одноразовый. Они нужны, если телефон потерян или приложение
        не открывается. Показываем их один раз — сохраните в менеджер паролей или распечатайте.</p>

    <div class="codes">
        @foreach ($codes as $code)
            <div>{{ $code }}</div>
        @endforeach
    </div>

    <a class="button" href="/admin">Я сохранил коды, продолжить</a>
@endsection
