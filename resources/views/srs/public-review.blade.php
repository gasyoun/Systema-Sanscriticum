@extends('layouts.shop')

@section('title', 'Карточки — пробный режим')

@section('content')
<div class="bg-slate-100 min-h-[70vh]">
    @livewire('srs-review', ['slug' => $slug])
</div>
@endsection
