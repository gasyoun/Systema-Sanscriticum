@extends('layouts.student')

@section('title', 'Карточки')
@section('header', 'Карточки')

@section('content')
    @livewire('srs-review', ['slug' => $slug ?? null])
@endsection
